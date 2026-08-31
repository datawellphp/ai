<?php

declare(strict_types=1);

use Datawell\Ai\Adapters\LaravelAi\BridgeTool;
use Datawell\Ai\Adapters\LaravelAi\DatawellTools;
use Datawell\Ai\Bridge\Mode;
use Datawell\Ai\Tests\Fixtures\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    $this->seedDatabase();
});

function bridgeTool(array $tools, string $name): BridgeTool
{
    foreach ($tools as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("No tool {$name}.");
}

it('wraps every bridge tool as a laravel/ai tool with a name, prose and a JSON schema', function (): void {
    $tools = DatawellTools::for($this->viewer());

    expect($tools)->each->toBeInstanceOf(Tool::class)
        ->and(array_map(static fn (BridgeTool $tool): string => $tool->name(), $tools))->toContain('query_people', 'people_flag', 'lookup');

    $query = bridgeTool($tools, 'query_people');
    $schema = $query->schema(new JsonSchemaTypeFactory);

    expect($query->description())->toContain('Query the "People" source')
        ->and($schema)->toHaveKeys(['filters', 'sorts', 'page'])
        ->and(DatawellTools::for($this->viewer(), Mode::Compact)[0]->name())->toBe('list_sources');
});

it('requests approval per call with the bridge reason, and passes the tool-call id through as the approval', function (): void {
    $tools = DatawellTools::for($this->viewer());
    $anna = User::query()->where('name', 'Anna')->firstOrFail();
    $flag = bridgeTool($tools, 'people_flag');

    expect($flag)->toBeInstanceOf(Approvable::class);

    $arguments = ['target' => ['ids' => [$anna->id]]];
    $approval = $flag->shouldRequestApproval(new Request($arguments, 'call_42'));

    expect($approval?->reason)->toBe("Flag on People: 1 row ({$anna->id}).");

    // The framework runs handle() only after the user approved: the call id is the approval reference.
    $report = json_decode($flag->handle(new Request($arguments, 'call_42')), true);

    expect($report['counts']['succeeded'])->toBe(1)->and($anna->refresh()->email)->toBe('flagged@acme.test');

    // A never-confirm action needs no approval and runs straight through.
    $touch = bridgeTool($tools, 'people_touch');
    expect($touch->shouldRequestApproval(new Request($arguments, 'call_43')))->toBeNull()
        ->and(json_decode($touch->handle(new Request($arguments, 'call_43')), true)['status'])->toBe('completed');
});

it('lets the host force approval on or off, with consent still verified by the core', function (): void {
    $tools = DatawellTools::for($this->viewer());
    $anna = User::query()->where('name', 'Anna')->firstOrFail();

    $touch = bridgeTool($tools, 'people_touch')->requireApproval('House rule.');
    expect($touch->shouldRequestApproval(new Request([], 'c'))?->reason)->toBe('House rule.');

    // Switching approval off on a confirm action does not bypass the pipeline: the core refuses.
    $flag = bridgeTool($tools, 'people_flag')->withoutApproval();
    expect($flag->shouldRequestApproval(new Request([], 'c')))->toBeNull()
        ->and(json_decode($flag->handle(new Request(['target' => ['ids' => [$anna->id]]], 'c')), true)['error'])->toContain('requires user approval');
});

it('returns validation problems as tool results, not exceptions', function (): void {
    $query = bridgeTool(DatawellTools::for($this->viewer()), 'query_people');

    $result = json_decode($query->handle(new Request(['filters' => ['conditions' => [['filter' => 'nope', 'operator' => 'equals', 'value' => 1]]]], 'c')), true);

    expect($result)->toHaveKey('errors');
});
