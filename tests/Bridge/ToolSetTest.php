<?php

declare(strict_types=1);

use Datawell\Ai\Bridge\Mode;
use Datawell\Ai\Bridge\ToolDefinition;
use Datawell\Ai\Bridge\ToolSet;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

beforeEach(function (): void {
    $this->seedDatabase();
});

function toolNames(array $tools): array
{
    return array_map(static fn (ToolDefinition $tool): string => $tool->name, $tools);
}

function tool(array $tools, string $name): ToolDefinition
{
    foreach ($tools as $tool) {
        if ($tool->name === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("No tool {$name}.");
}

function inputSchema(ToolDefinition $tool): array
{
    return array_map(static fn ($type): array => $type->toArray(), $tool->schema(new JsonSchemaTypeFactory));
}

it('generates one query tool per source, one tool per server action, and the shared primitives', function (): void {
    $tools = app(ToolSet::class)->for($this->viewer());

    expect(toolNames($tools))->toBe([
        'query_people', 'people_touch', 'people_flag', 'people_deactivate',
        'query_tags',
        'lookup', 'values', 'progress',
    ]);
});

it('derives real constraints from the published schema, as the acting user', function (): void {
    $query = tool(app(ToolSet::class)->for($this->viewer()), 'query_people');
    $schema = inputSchema($query);

    expect($schema['filters']['properties']['conditions']['items']['properties']['filter']['enum'])->toBe(['name', 'active', 'tags'])
        ->and($schema['filters']['properties']['conditions']['items']['properties']['operator']['enum'])->toContain('contains', 'is', 'hasAny')
        ->and($schema['sorts']['items']['properties']['key']['enum'])->toBe(['name', 'tags_count'])
        ->and($schema['groupBy']['items']['properties']['key']['enum'])->toBe(['active'])
        ->and($schema['select']['items']['enum'])->not->toContain('email')
        ->and($query->description)->toContain('Query the "People" source.')
        ->and($query->description)->toContain('active is true')
        ->and($query->description)->not->toContain('email "Email"');

    // The privileged user's tool knows the gated field (D17 per-user contract).
    $privileged = tool(app(ToolSet::class)->for($this->privilegedViewer()), 'query_people');

    expect(inputSchema($privileged)['select']['items']['enum'])->toContain('email')
        ->and($privileged->description)->toContain('email "Email"');
});

it('shapes action inputs from targets and parameters', function (): void {
    $tools = app(ToolSet::class)->for($this->viewer());

    $flag = inputSchema(tool($tools, 'people_flag'));
    expect($flag['target']['properties'])->toHaveKey('ids')
        ->and($flag['target']['properties'])->not->toHaveKey('query')
        ->and($flag['input']['properties']['note']['type'])->toBe('string');

    $deactivate = inputSchema(tool($tools, 'people_deactivate'));
    expect($deactivate['target']['properties'])->toHaveKeys(['ids', 'query', 'except']);

    expect(tool($tools, 'people_flag')->description)->toContain('The user will be asked to approve')
        ->and(tool($tools, 'people_touch')->description)->not->toContain('asked to approve');
});

it('decides approval per call with a reason a person can act on', function (): void {
    $tools = app(ToolSet::class)->for($this->viewer());

    expect(tool($tools, 'people_touch')->isApprovable())->toBeFalse()
        ->and(tool($tools, 'people_flag')->approvalReason(['target' => ['ids' => [1, 2]], 'input' => ['note' => 'hi']]))
        ->toBe('Flag on People: 2 rows (1, 2). Input: {"note":"hi"}.');

    // A query target carries the resolved count (D40): active people in workspace 1.
    $reason = tool($tools, 'people_deactivate')->approvalReason(['target' => ['query' => ['filters' => ['conditions' => [['filter' => 'active', 'operator' => 'is', 'value' => true]]]]]]);
    expect($reason)->toBe('Deactivate on People: 2 matching rows.');
});

it('publishes the compact mode as a fixed tool list', function (): void {
    config()->set('datawell-ai.mode', 'compact');
    $tools = app(ToolSet::class)->for($this->viewer());

    expect(toolNames($tools))->toBe(['list_sources', 'describe_source', 'query', 'act', 'lookup', 'values', 'progress'])
        ->and(tool($tools, 'list_sources')->handle([])['sources'][0]['key'])->toBe('people')
        ->and(tool($tools, 'describe_source')->handle(['source' => 'people'])['fields'])->not->toBeEmpty()
        ->and(inputSchema(tool($tools, 'query'))['source']['enum'])->toBe(['people', 'tags']);

    // Explicit mode wins over config.
    expect(toolNames(app(ToolSet::class)->for($this->viewer(), Mode::PerSource)))->toContain('query_people');
});
