<?php

declare(strict_types=1);

namespace Datawell\Ai\Tests;

use Datawell\Ai\AiServiceProvider;
use Datawell\Ai\Tests\Fixtures\Models\User;
use Datawell\Ai\Tests\Fixtures\Sources\People;
use Datawell\Ai\Tests\Fixtures\Sources\Tags;
use Datawell\DatawellServiceProvider;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DatawellServiceProvider::class,
            AiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('datawell.sources', [People::class, Tags::class]);
        $app['config']->set('datawell.lint.enabled', true);
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('queue.batching.database', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('view-contact-details', fn (User $user): bool => $user->hasAbility('view-contact-details'));
    }

    protected function seedDatabase(): void
    {
        $schema = require __DIR__.'/Fixtures/Database/schema.php';
        $schema->create();
        $schema->seed();
    }

    protected function viewer(): User
    {
        return User::query()->create(['name' => 'Viewer', 'email' => 'viewer@acme.test', 'workspace_id' => 2, 'abilities' => []]);
    }

    protected function privilegedViewer(): User
    {
        return User::query()->create(['name' => 'Privileged', 'email' => 'privileged@acme.test', 'workspace_id' => 2, 'abilities' => ['view-contact-details']]);
    }
}
