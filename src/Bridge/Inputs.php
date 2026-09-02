<?php

declare(strict_types=1);

namespace Datawell\Ai\Bridge;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;

/**
 * Tool input schemas derived mechanically from a published Datawell schema (D59):
 * filter keys, operators, sort keys, grains and enum values become real constraints,
 * so a model makes fewer malformed calls. Anything the grammar leaves open (a filter
 * value's shape depends on its operator) is documented in the tool's prose instead.
 */
final class Inputs
{
    /**
     * The input of a query tool. With `$withSource`, a `source` enum leads (compact mode).
     *
     * @param  array<string, mixed>  $schema  one source's published schema
     * @param  list<string>  $sourceKeys
     * @return array<string, Type>
     */
    public static function query(array $schema, JsonSchema $s, array $sourceKeys = []): array
    {
        $fields = self::list($schema, 'fields');
        $filters = self::list($schema, 'filters');
        $sorts = self::list($schema, 'sorts');
        $input = [];

        if ($sourceKeys !== []) {
            $input['source'] = $s->string()->enum($sourceKeys)->required()->description('The source key.');
        }

        $parameters = self::parameters($schema, $s);

        if ($parameters !== null) {
            $input['parameters'] = $parameters;
        }

        if (array_filter($fields, static fn (array $field): bool => (bool) ($field['searchable'] ?? false)) !== []) {
            $input['search'] = $s->string()->description('Free-text search across the searchable fields.');
        }

        if ($filters !== []) {
            $operators = array_values(array_unique(array_merge(...array_map(static fn (array $filter): array => $filter['operators'], $filters))));
            $input['filters'] = $s->object([
                'boolean' => $s->string()->enum(['and', 'or'])->description('How the conditions combine; defaults to and.'),
                'conditions' => $s->array()->items($s->object([
                    'filter' => $s->string()->enum(array_column($filters, 'key'))->required(),
                    'operator' => $s->string()->enum($operators)->required(),
                    'value' => $s->anyOf([
                        $s->string(),
                        $s->number(),
                        $s->boolean(),
                        $s->array()->items($s->anyOf([$s->string(), $s->number()])),
                        $s->object([]),
                    ])->description('Shape depends on the operator — see the tool description. Omit for isEmpty/isNotEmpty.'),
                ]))->required(),
            ])->description('Filter conditions. Each filter accepts only its listed operators.');
        }

        if ($sorts !== []) {
            $input['sorts'] = $s->array()->items($s->object([
                'key' => $s->string()->enum(array_column($sorts, 'key'))->required(),
                'direction' => $s->string()->enum(['asc', 'desc'])->required(),
            ]))->description('Sort order; omit for the default sort.');
        }

        $groupable = array_values(array_filter($fields, static fn (array $field): bool => (bool) ($field['groupable'] ?? false)));
        $measurable = array_values(array_filter($fields, static fn (array $field): bool => ($field['aggregates'] ?? []) !== []));

        if ($groupable !== []) {
            $grains = array_values(array_unique(array_merge(...array_map(static fn (array $field): array => $field['grains'] ?? [], $groupable))));
            $group = ['key' => $s->string()->enum(array_column($groupable, 'key'))->required()];

            if ($grains !== []) {
                $group['grain'] = $s->string()->enum($grains)->description('Required for date fields: the bucket size.');
            }

            $input['groupBy'] = $s->array()->items($s->object($group))
                ->description('Group rows into buckets instead of listing them. Combine with aggregates.');

            $aggregate = ['fn' => $s->string()->enum(['count', 'sum', 'avg', 'min', 'max'])->required()];

            if ($measurable !== []) {
                $aggregate['field'] = $s->string()->enum(array_column($measurable, 'key'))->description('Omit for count.');
            }

            $input['aggregates'] = $s->array()->items($s->object($aggregate))
                ->description('Measures per bucket; count needs no field.');
        }

        $input['select'] = $s->array()->items($s->string()->enum(array_column($fields, 'key')))
            ->description('Only these fields in each row; omit for all visible fields.');

        $input['page'] = $s->object([
            'size' => $s->integer()->min(1)->description('Rows per page (the source enforces a ceiling).'),
            'number' => $s->integer()->min(1)->description('Offset paging: the page number (also returns a total).'),
            'after' => $s->string()->description('Cursor paging: the nextCursor from the previous page.'),
            'withTotal' => $s->boolean(),
        ]);

        return $input;
    }

    /** @var list<string> The presentation vocabulary a chart tool accepts (mirrors datawell/reports). */
    public const array CHART_TYPES = ['bar', 'barHorizontal', 'line', 'area', 'pie', 'number', 'table'];

    /**
     * The input of a chart tool (D63): the grouped part of a query plus a title
     * and a presentation. Listing-only keys (sorts, select, page) do not apply.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $sourceKeys
     * @return array<string, Type>
     */
    public static function chart(array $schema, JsonSchema $s, array $sourceKeys = []): array
    {
        $input = self::query($schema, $s, $sourceKeys);
        unset($input['sorts'], $input['select'], $input['page']);

        if (! isset($input['groupBy'])) {
            // Nothing groupable in this source's schema (compact mode's stand-in included):
            // leave the shape open and let the executor validate.
            $input['groupBy'] = $s->array()->items($s->object([
                'key' => $s->string()->required(),
                'grain' => $s->string()->enum(['day', 'week', 'month', 'quarter', 'year']),
            ]))->description('One or two dimensions; a date field needs a grain. Omit only for a number chart.');
            $input['aggregates'] = $s->array()->items($s->object([
                'fn' => $s->string()->enum(['count', 'sum', 'avg', 'min', 'max'])->required(),
                'field' => $s->string()->description('Omit for count.'),
            ]))->required()->description('One or more measures per bucket.');
        } else {
            $input['groupBy'] = $input['groupBy']->description('One or two dimensions; the second becomes the series. Omit only for a number chart.');
            $input['aggregates'] = $input['aggregates']->required()->description('One or more measures per bucket; count needs no field.');
        }

        return [
            'title' => $s->string()->required()->description('A short chart title in the user\'s words.'),
            'type' => $s->string()->enum(self::CHART_TYPES)->required()
                ->description('line or area for a date dimension; bar for categories; barHorizontal for long labels; pie for shares of a few categories; number for one total with no dimension; table for exact figures.'),
            'options' => $s->object([
                'stacked' => $s->boolean()->description('Stack series in a bar chart.'),
                'donut' => $s->boolean()->description('Render a pie as a donut.'),
            ]),
            ...$input,
        ];
    }

    /**
     * The input of an action tool: source parameters, the target, the action's own inputs.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $action
     * @param  list<string>  $sourceKeys
     * @return array<string, Type>
     */
    public static function action(array $schema, array $action, JsonSchema $s, array $sourceKeys = []): array
    {
        $input = [];

        if ($sourceKeys !== []) {
            $input['source'] = $s->string()->enum($sourceKeys)->required()->description('The source key.');
            $input['action'] = $s->string()->required()->description('The action key, as published by describe_source.');
        }

        $parameters = self::parameters($schema, $s);

        if ($parameters !== null) {
            $input['parameters'] = $parameters;
        }

        $target = self::target($action, $s);

        if ($target !== null) {
            $input['target'] = $target;
        }

        $inputs = self::parameterObject(self::list($action, 'parameters'), $s);

        if ($inputs !== null) {
            $input['input'] = $inputs->description('The action\'s own inputs.');
        }

        return $input;
    }

    /**
     * The target shapes an action accepts (D40): `{ids}` and/or `{query, except}`; none for standalone.
     *
     * @param  array<string, mixed>  $action
     */
    public static function target(array $action, JsonSchema $s): ?ObjectType
    {
        /** @var list<string> $targets */
        $targets = $action['targets'] ?? [];

        if ($targets === [] || $targets === ['standalone']) {
            return null;
        }

        $properties = [];
        $id = $s->anyOf([$s->integer(), $s->string()]);

        if (in_array('single', $targets, true) || in_array('many', $targets, true)) {
            $ids = $s->array()->items($id)->min(1);

            if (! in_array('many', $targets, true)) {
                $ids = $ids->max(1);
            }

            $properties['ids'] = $ids->description('The row ids to act on.');
        }

        if (in_array('queryScope', $targets, true)) {
            $properties['query'] = $s->object([
                'filters' => $s->object([]),
                'search' => $s->string(),
            ])->description('Act on every row matching this query (same filters/search shape as the query tool). Requires the user\'s approval with the count shown.');
            $properties['except'] = $s->array()->items($id)->description('Ids to leave out of a query target.');
        }

        return $s->object($properties)->required()->description('Exactly one of ids or query.');
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public static function parameters(array $schema, JsonSchema $s): ?ObjectType
    {
        return self::parameterObject(self::list($schema, 'parameters'), $s);
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     */
    private static function parameterObject(array $parameters, JsonSchema $s): ?ObjectType
    {
        if ($parameters === []) {
            return null;
        }

        $properties = [];
        $anyRequired = false;

        foreach ($parameters as $parameter) {
            $type = self::typeFor($parameter, $s);

            if ($parameter['required'] ?? false) {
                $type = $type->required();
                $anyRequired = true;
            }

            $properties[$parameter['key']] = $type;
        }

        $object = $s->object($properties);

        return $anyRequired ? $object->required() : $object;
    }

    /**
     * A parameter's JSON type from its published Laravel rules; the rules and any
     * provenance ride along as the description so the model knows where values come from.
     *
     * @param  array<string, mixed>  $parameter
     */
    public static function typeFor(array $parameter, JsonSchema $s): Type
    {
        /** @var list<string> $rules */
        $rules = $parameter['rules'] ?? [];
        $type = match (true) {
            in_array('integer', $rules, true) => $s->integer(),
            in_array('numeric', $rules, true) => $s->number(),
            in_array('boolean', $rules, true) => $s->boolean(),
            in_array('array', $rules, true) => $s->array(),
            default => $s->string(),
        };

        $notes = [(string) ($parameter['label'] ?? $parameter['key'])];

        if (isset($parameter['from']['source'])) {
            $notes[] = sprintf('ids come from the "%s" source', $parameter['from']['source']);
        }

        if ($rules !== []) {
            $notes[] = 'rules: '.implode(', ', $rules);
        }

        return $type->description(implode('; ', $notes));
    }

    /**
     * @param  array<string, mixed>  $container
     * @return list<array<string, mixed>>
     */
    private static function list(array $container, string $key): array
    {
        /** @var array<int|string, array<string, mixed>> $items */
        $items = $container[$key] ?? [];

        return array_values($items);
    }
}
