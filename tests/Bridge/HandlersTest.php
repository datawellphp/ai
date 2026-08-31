<?php

declare(strict_types=1);

use Datawell\Actions\Runs\ActionRun;
use Datawell\Ai\Bridge\ToolDefinition;
use Datawell\Ai\Bridge\ToolSet;
use Datawell\Ai\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->seedDatabase();
});

function pick(array $tools, string $name): ToolDefinition
{
    foreach ($tools as $tool) {
        if ($tool->name === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("No tool {$name}.");
}

it('runs queries on the delegated channel and returns the wire result', function (): void {
    $tools = app(ToolSet::class)->for($this->viewer());

    $result = pick($tools, 'query_people')->handle(['search' => 'an', 'page' => ['size' => 10]]);

    expect(array_column($result['rows'], 'name'))->toBe(['Anna'])
        ->and($result['meta']['mode'])->toBe('cursor')
        ->and($result['rows'][0])->not->toHaveKey('email');
});

it('returns validation failures as results the agent can read', function (): void {
    $tools = app(ToolSet::class)->for($this->viewer());

    $result = pick($tools, 'query_people')->handle(['sorts' => [['key' => 'email', 'direction' => 'asc']]]);

    expect($result)->toHaveKeys(['error', 'errors'])
        ->and($result['errors'])->not->toBeEmpty();

    // Delegated channels get the stricter page ceiling (D39).
    $result = pick($tools, 'query_people')->handle(['page' => ['size' => 100]]);

    expect($result['error'])->toContain('Page size may not exceed 50');
});

it('groups into buckets through the same tool', function (): void {
    $tools = app(ToolSet::class)->for($this->viewer());

    $result = pick($tools, 'query_people')->handle([
        'groupBy' => [['key' => 'active']],
        'aggregates' => [['fn' => 'count']],
    ]);

    // The defaulted active filter still applies: one bucket, the active people.
    expect($result['buckets'])->toHaveCount(1)
        ->and($result['buckets'][0]['count'])->toBe(2)
        ->and($result['meta'])->toHaveKey('truncated');
});

it('runs a never-confirm action without approval, and refuses a confirm action without one', function (): void {
    $tools = app(ToolSet::class)->for($this->viewer());
    $anna = User::query()->where('name', 'Anna')->firstOrFail();

    $touch = pick($tools, 'people_touch')->handle(['target' => ['ids' => [$anna->id]]]);
    expect($touch['status'])->toBe('completed')->and($touch['message'])->toBe('Checked.');

    // Consent is verified in the pipeline (D37): no approval reference, no run.
    $flag = pick($tools, 'people_flag')->handle(['target' => ['ids' => [$anna->id]]]);
    expect($flag['error'])->toContain('requires user approval');
    expect($anna->refresh()->email)->not->toBe('flagged@acme.test');

    // With the approval reference the run proceeds and the input reaches the action.
    $flag = pick($tools, 'people_flag')->handle(['target' => ['ids' => [$anna->id]], 'input' => ['note' => 'ok']], 'call_123');
    expect($flag['counts']['succeeded'])->toBe(1)->and($anna->refresh()->email)->toBe('flagged@acme.test');
});

it('runs a query-scoped action and records the approval', function (): void {
    config()->set('datawell.actions.record', 'always');
    $tools = app(ToolSet::class)->for($this->viewer());

    $report = pick($tools, 'people_deactivate')->handle([
        'target' => ['query' => ['filters' => ['conditions' => [['filter' => 'name', 'operator' => 'startsWith', 'value' => 'A']]]]],
    ], 'call_scope');

    expect($report['counts'])->toBe(['targeted' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0])
        ->and(User::query()->where('name', 'Anna')->firstOrFail()->active)->toBeFalse();

    $run = ActionRun::query()->sole();
    expect($run->channel)->toBe('delegatedInteractive')->and($run->approval)->toBe('call_scope');
});

it('serves lookup, values and progress', function (): void {
    $tools = app(ToolSet::class)->for($this->viewer());
    $anna = User::query()->where('name', 'Anna')->firstOrFail();

    expect(pick($tools, 'lookup')->handle(['source' => 'people', 'id' => $anna->id]))->toBe(['id' => $anna->id, 'label' => 'Anna', 'url' => "/people/{$anna->id}"])
        ->and(pick($tools, 'lookup')->handle(['source' => 'people', 'id' => 999]))->toBe(['error' => 'Not found.'])
        ->and(pick($tools, 'values')->handle(['source' => 'people', 'id' => $anna->id, 'field' => 'tags', 'page' => ['size' => 1]])['rows'])->toHaveCount(1)
        ->and(pick($tools, 'progress')->handle(['runId' => '018f0000-0000-7000-8000-000000000000']))->toBe(['error' => 'Not found.']);
});

it('runs actions through the compact act tool with the same consent rules', function (): void {
    config()->set('datawell-ai.mode', 'compact');
    $tools = app(ToolSet::class)->for($this->viewer());
    $anna = User::query()->where('name', 'Anna')->firstOrFail();
    $act = pick($tools, 'act');

    expect($act->approvalReason(['source' => 'people', 'action' => 'flag', 'target' => ['ids' => [$anna->id]]]))->toContain('Flag on People: 1 row')
        ->and($act->approvalReason(['source' => 'people', 'action' => 'touch', 'target' => ['ids' => [$anna->id]]]))->toBeNull()
        ->and($act->handle(['source' => 'people', 'action' => 'touch', 'target' => ['ids' => [$anna->id]]])['status'])->toBe('completed')
        ->and($act->handle(['source' => 'people', 'action' => 'purge', 'target' => ['ids' => [$anna->id]]])['errors']['action'][0])->toBe('Unknown action "purge".');
});
