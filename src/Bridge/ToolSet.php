<?php

declare(strict_types=1);

namespace Datawell\Ai\Bridge;

use Closure;
use Datawell\Registry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The registry projected onto tools for one user (D59/D60): every source the user
 * may know exists becomes tools, described as that user sees it — hidden fields,
 * gated actions and humanOnly actions are simply absent. Nothing here is written
 * per source; it is all generated from the published schema.
 */
final class ToolSet
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Calls $calls,
        private readonly Repository $config,
    ) {}

    /**
     * @return list<ToolDefinition>
     */
    public function for(Authenticatable $user, ?Mode $mode = null): array
    {
        $mode ??= Mode::from((string) $this->config->get('datawell-ai.mode', Mode::PerSource->value));
        $schemas = [];

        foreach ($this->registry->availableFor($user) as $source) {
            $schemas[$source->key()] = $source->describe($user)->toArray();
        }

        $tools = $mode === Mode::PerSource ? $this->perSource($user, $schemas) : $this->compact($user, $schemas);

        return [...$tools, ...$this->shared($user, array_keys($schemas))];
    }

    /**
     * @param  array<string, array<string, mixed>>  $schemas
     * @return list<ToolDefinition>
     */
    private function perSource(Authenticatable $user, array $schemas): array
    {
        $tools = [];

        foreach ($schemas as $key => $schema) {
            $tools[] = new ToolDefinition(
                name: Names::tool('query', $key),
                description: Prose::query($schema),
                schema: static fn (JsonSchema $s): array => Inputs::query($schema, $s),
                handler: fn (array $arguments): array => $this->calls->query($user, ['source' => $key, ...self::compactArguments($arguments)]),
            );

            foreach ($schema['actions'] as $action) {
                // humanOnly is hidden-means-absent applied to the channel (D37): never a tool.
                if ($action['kind'] !== 'server' || ($action['humanOnly'] ?? false)) {
                    continue;
                }

                $tools[] = new ToolDefinition(
                    name: Names::tool($key, $action['key']),
                    description: Prose::action($schema, $action),
                    schema: static fn (JsonSchema $s): array => Inputs::action($schema, $action, $s),
                    handler: fn (array $arguments, ?string $approval): array => $this->calls->act($user, $this->actionWire($key, $action['key'], $arguments), $approval),
                    approval: $this->approvalFor($user, $schema, $action),
                );
            }
        }

        return $tools;
    }

    /**
     * @param  array<string, array<string, mixed>>  $schemas
     * @return list<ToolDefinition>
     */
    private function compact(Authenticatable $user, array $schemas): array
    {
        $keys = array_keys($schemas);
        $summary = implode('; ', array_map(
            static fn (array $schema): string => sprintf('%s — %s: %s', $schema['source']['key'], $schema['source']['name'], $schema['source']['description']),
            $schemas,
        ));

        $lookupAction = static function (array $arguments) use ($schemas): ?array {
            $schema = $schemas[$arguments['source'] ?? ''] ?? null;

            foreach ($schema['actions'] ?? [] as $action) {
                if ($action['key'] === ($arguments['action'] ?? null) && $action['kind'] === 'server' && ! ($action['humanOnly'] ?? false)) {
                    return $action;
                }
            }

            return null;
        };

        return [
            new ToolDefinition(
                name: 'list_sources',
                description: 'List the data sources available to the user: key, name and description. Sources: '.$summary,
                schema: static fn (JsonSchema $s): array => [],
                handler: static fn (): array => ['sources' => array_values(array_map(
                    static fn (array $schema): array => $schema['source'],
                    $schemas,
                ))],
            ),
            new ToolDefinition(
                name: 'describe_source',
                description: 'The full contract of one source as this user sees it: parameters, fields, filters and their operators, sorts, and actions. Call this before querying a source.',
                schema: static fn (JsonSchema $s): array => ['source' => $s->string()->enum($keys)->required()],
                handler: static fn (array $arguments): array => $schemas[$arguments['source'] ?? ''] ?? ['error' => 'Not found.'],
            ),
            new ToolDefinition(
                name: 'query',
                description: 'Query any source with the Datawell request shape (see describe_source for the source\'s filters, operators, sorts and parameters). '.Prose::query(self::genericSchema()),
                schema: static fn (JsonSchema $s): array => Inputs::query(self::genericSchema(), $s, $keys),
                handler: fn (array $arguments): array => $this->calls->query($user, self::compactArguments($arguments)),
            ),
            new ToolDefinition(
                name: 'act',
                description: 'Run a server action published by a source (see describe_source). Target is {ids: [...]} or, where the action allows queryScope, {query: {filters, search}, except: [...]}. Actions that require confirmation will ask the user for approval first.',
                schema: static fn (JsonSchema $s): array => Inputs::action(self::genericSchema(), ['targets' => ['many', 'queryScope']], $s, $keys) + [
                    'input' => $s->object([])->description('The action\'s own inputs, as published.'),
                ],
                handler: fn (array $arguments, ?string $approval): array => $this->calls->act(
                    $user,
                    $this->actionWire((string) ($arguments['source'] ?? ''), (string) ($arguments['action'] ?? ''), $arguments),
                    $approval,
                ),
                approval: function (array $arguments) use ($user, $schemas, $lookupAction): ?string {
                    $action = $lookupAction($arguments);
                    $schema = $schemas[$arguments['source'] ?? ''] ?? null;

                    if ($action === null || $schema === null) {
                        return null;
                    }

                    $decide = $this->approvalFor($user, $schema, $action);

                    return $decide === null ? null : $decide($arguments);
                },
            ),
        ];
    }

    /**
     * lookup / values / progress: the same three primitives in either mode.
     *
     * @param  list<string>  $keys
     * @return list<ToolDefinition>
     */
    private function shared(Authenticatable $user, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $id = static fn (JsonSchema $s) => $s->anyOf([$s->integer(), $s->string()])->required()->description('The entity id.');
        $parameters = static fn (JsonSchema $s) => $s->object([])->description('Source parameters, if the source declares any.');

        return [
            new ToolDefinition(
                name: 'lookup',
                description: 'Resolve one entity by source and id to its reference {id, label, url?}. Use it to name an id you were given, or to check an entity is within the user\'s reach — an entity outside it reads as not found.',
                schema: static fn (JsonSchema $s): array => [
                    'source' => $s->string()->enum($keys)->required(),
                    'id' => $id($s),
                    'parameters' => $parameters($s),
                ],
                handler: fn (array $arguments): array => $this->calls->lookup($user, (string) $arguments['source'], self::id($arguments['id']), self::object($arguments['parameters'] ?? [])),
            ),
            new ToolDefinition(
                name: 'values',
                description: 'Page through every value of a many-valued relation field on one row (rows carry only the first few plus a total).',
                schema: static fn (JsonSchema $s): array => [
                    'source' => $s->string()->enum($keys)->required(),
                    'id' => $id($s),
                    'field' => $s->string()->required()->description('The many-valued field key.'),
                    'page' => $s->object(['size' => $s->integer()->min(1), 'after' => $s->string(), 'number' => $s->integer()->min(1)]),
                    'parameters' => $parameters($s),
                ],
                handler: fn (array $arguments): array => $this->calls->values(
                    $user,
                    (string) $arguments['source'],
                    self::id($arguments['id']),
                    (string) $arguments['field'],
                    self::object($arguments['parameters'] ?? []),
                    self::object($arguments['page'] ?? []),
                ),
            ),
            new ToolDefinition(
                name: 'progress',
                description: 'The progress of a queued action run by its runId, or its final report once finished.',
                schema: static fn (JsonSchema $s): array => ['runId' => $s->string()->required()],
                handler: fn (array $arguments): array => $this->calls->progress($user, (string) $arguments['runId']),
            ),
        ];
    }

    /**
     * The approval decision for an action tool (D59): required whenever the action's
     * effective confirmation is not Never — on a delegated channel that is every
     * WhenDelegated and Always action — with a reason a person can act on. A query
     * target carries the resolved count (D40).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $action
     * @return (Closure(array<string, mixed>): ?string)|null
     */
    private function approvalFor(Authenticatable $user, array $schema, array $action): ?Closure
    {
        if (($action['confirmation'] ?? 'whenDelegated') === 'never') {
            return null;
        }

        return function (array $arguments) use ($user, $schema, $action): string {
            $target = self::object($arguments['target'] ?? []);
            $reason = sprintf('%s on %s', $action['label'], $schema['source']['name']);

            if (isset($target['query'])) {
                $count = $this->calls->countScope($user, $schema['source']['key'], self::object($arguments['parameters'] ?? []), self::object($target['query']));
                $except = count(self::list($target['except'] ?? []));
                $reason .= $count === null
                    ? ': every row matching the given query'
                    : sprintf(': %d matching row%s', max(0, $count - $except), $count - $except === 1 ? '' : 's');
            } elseif (isset($target['ids'])) {
                $ids = self::list($target['ids']);
                $reason .= sprintf(': %d row%s (%s)', count($ids), count($ids) === 1 ? '' : 's', implode(', ', array_map('strval', array_slice($ids, 0, 10))).(count($ids) > 10 ? ', …' : ''));
            }

            if (($action['destructive'] ?? false) === true) {
                $reason .= '. Destructive — cannot be undone';
            }

            $input = self::object($arguments['input'] ?? []);

            if ($input !== []) {
                $reason .= '. Input: '.json_encode($input);
            }

            return $reason.'.';
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function actionWire(string $source, string $action, array $arguments): array
    {
        $wire = ['source' => $source, 'action' => $action];

        foreach (['parameters', 'target', 'input'] as $key) {
            if (isset($arguments[$key]) && $arguments[$key] !== []) {
                $wire[$key] = $arguments[$key];
            }
        }

        return $wire;
    }

    /**
     * Drop empty/null arguments so the strict wire parser sees only what the model meant.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function compactArguments(array $arguments): array
    {
        return array_filter($arguments, static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * A schema-shaped stand-in for compact mode, where the source is an argument:
     * no per-source fields, filters or sorts to constrain — the prose points at describe_source.
     *
     * @return array<string, mixed>
     */
    private static function genericSchema(): array
    {
        return [
            'source' => ['key' => '*', 'name' => 'any source', 'description' => 'the source named by the source argument', 'timezone' => 'the source\'s published timezone'],
            'parameters' => [],
            'fields' => [],
            'filters' => [['key' => '*', 'operators' => ['equals', 'notEquals', 'contains', 'startsWith', 'endsWith', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'notIn', 'hasAny', 'hasAll', 'hasNone', 'is', 'on', 'before', 'after', 'isEmpty', 'isNotEmpty']]],
            'sorts' => [],
            'defaultSort' => [],
            'actions' => [],
        ];
    }

    private static function id(mixed $value): int|string
    {
        return is_int($value) ? $value : (string) (is_scalar($value) ? $value : '');
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
