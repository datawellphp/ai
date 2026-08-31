<?php

declare(strict_types=1);

namespace Datawell\Ai\Bridge;

/**
 * The AI-facing descriptions (D32: key for machines, label for humans, description
 * for the AI). Everything here is read straight off the published schema — the
 * source and action prose the author wrote, the fields, operators and defaults —
 * so the model gets the same contract the table does, in sentences.
 */
final class Prose
{
    private const string VALUE_SHAPES = 'Value shapes by operator: text operators take a string; number gt/gte/lt/lte/equals take a number and between takes {"from","to"}; '
        .'boolean "is" takes true/false; enum and relation in/notIn/hasAny/hasAll/hasNone take an array of ids; '
        .'date operators take "YYYY-MM-DD" (or an ISO instant) or a relative object — {"relative":"this","unit":"month"}, {"relative":"last","n":7,"unit":"days"}, '
        .'{"relative":"ago","n":30,"unit":"days"}, {"relative":"today"} — resolved in the source timezone; isEmpty/isNotEmpty take no value.';

    /**
     * @param  array<string, mixed>  $schema
     */
    public static function query(array $schema): string
    {
        $source = $schema['source'];
        $lines = [sprintf('Query the "%s" source. %s', $source['name'], $source['description'])];

        if (($schema['parameters'] ?? []) !== []) {
            $lines[] = 'Parameters: '.implode('; ', array_map(self::parameter(...), $schema['parameters'])).'.';
        }

        $lines[] = 'Fields: '.implode('; ', array_map(self::field(...), $schema['fields'])).'.';

        if (($schema['filters'] ?? []) !== []) {
            $lines[] = 'Filters and their operators: '.implode('; ', array_map(
                static fn (array $filter): string => sprintf('%s [%s]', $filter['key'], implode(', ', $filter['operators'])),
                $schema['filters'],
            )).'.';

            $defaults = array_filter($schema['filters'], static fn (array $filter): bool => isset($filter['default']));

            if ($defaults !== []) {
                $lines[] = 'Applied by default unless you filter on them explicitly: '.implode('; ', array_map(
                    static fn (array $filter): string => sprintf('%s %s %s', $filter['key'], $filter['default']['operator'] ?? '', json_encode($filter['default']['value'] ?? null)),
                    $defaults,
                )).'.';
            }
        }

        if (($schema['sorts'] ?? []) !== []) {
            $default = ($schema['defaultSort'] ?? []) === [] ? 'none' : implode(', ', array_map(
                static fn (array $sort): string => $sort['key'].' '.$sort['direction'],
                $schema['defaultSort'],
            ));
            $lines[] = sprintf('Sortable by: %s. Default sort: %s.', implode(', ', array_column($schema['sorts'], 'key')), $default);
        }

        $lines[] = self::VALUE_SHAPES;
        $lines[] = sprintf(
            'Timezone: %s. Rows carry an id, an optional url, the actions available on that row, and each field; references are {id, label, url?}; many-valued fields are {items, total} (fetch the rest with the values tool). '
            .'Use groupBy + aggregates to get buckets instead of rows. Use page.after (cursor) to continue a listing.',
            $source['timezone'],
        );

        return implode(' ', $lines);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $action
     */
    public static function action(array $schema, array $action): string
    {
        $source = $schema['source'];
        $lines = [sprintf('%s — %s (source "%s").', $action['label'], $action['description'] ?? 'runs the action', $source['name'])];

        /** @var list<string> $targets */
        $targets = $action['targets'] ?? [];
        $shapes = [];

        if (in_array('single', $targets, true) && ! in_array('many', $targets, true)) {
            $shapes[] = 'exactly one row id';
        } elseif (in_array('many', $targets, true)) {
            $shapes[] = 'one or more row ids';
        }

        if (in_array('queryScope', $targets, true)) {
            $shapes[] = 'or every row matching a query (target.query, with optional target.except)';
        }

        if ($shapes !== []) {
            $lines[] = 'Target: '.implode(', ', $shapes).'. Ids come from the query tool.';
        } elseif (in_array('standalone', $targets, true)) {
            $lines[] = 'Takes no target.';
        }

        if (($schema['parameters'] ?? []) !== []) {
            $lines[] = 'Parameters: '.implode('; ', array_map(self::parameter(...), $schema['parameters'])).'.';
        }

        if (($action['parameters'] ?? []) !== []) {
            $lines[] = 'Inputs: '.implode('; ', array_map(self::parameter(...), $action['parameters'])).'.';
        }

        if ($action['destructive'] ?? false) {
            $lines[] = 'Destructive: cannot be undone.';
        }

        if (($action['confirmation'] ?? 'whenDelegated') !== 'never') {
            $lines[] = 'The user will be asked to approve this call before it runs.';
        }

        $lines[] = 'Returns a report: status, counts (targeted/succeeded/failed/skipped), per-row failures with reasons, and a runId to poll with the progress tool when queued.';

        return implode(' ', $lines);
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    public static function parameter(array $parameter): string
    {
        $bits = [$parameter['key'], ($parameter['required'] ?? false) ? 'required' : 'optional'];

        if (($parameter['rules'] ?? []) !== []) {
            $bits[] = implode(' ', $parameter['rules']);
        }

        if (isset($parameter['from']['source'])) {
            $bits[] = sprintf('ids from the "%s" source', $parameter['from']['source']);
        }

        return sprintf('%s (%s)', array_shift($bits), implode(', ', $bits));
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function field(array $field): string
    {
        $type = $field['type'];

        if ($type === 'relation') {
            $type = sprintf('%s → %s%s', $type, $field['source'] ?? '?', ($field['cardinality'] ?? 'single') === 'many' ? ', many' : '');
        }

        if ($type === 'enum' && ($field['options']['strategy'] ?? null) === 'inline') {
            $type = 'enum: '.implode('/', array_map(
                static fn (array $value): string => $value['id'].'='.$value['label'],
                $field['options']['values'],
            ));
        }

        $flags = array_keys(array_filter([
            'sortable' => $field['sortable'] ?? false,
            'filterable' => $field['filterable'] ?? false,
            'searchable' => $field['searchable'] ?? false,
            'groupable' => $field['groupable'] ?? false,
            'nullable' => $field['nullable'] ?? false,
        ]));

        if (($field['grains'] ?? []) !== []) {
            $flags[] = 'grains '.implode('/', $field['grains']);
        }

        if (($field['aggregates'] ?? []) !== []) {
            $flags[] = 'aggregates '.implode('/', $field['aggregates']);
        }

        return sprintf('%s "%s" (%s%s)', $field['key'], $field['label'], $type, $flags === [] ? '' : '; '.implode(', ', $flags));
    }
}
