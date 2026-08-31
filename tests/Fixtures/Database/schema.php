<?php

declare(strict_types=1);

use Datawell\Ai\Tests\Fixtures\Models\Tag;
use Datawell\Ai\Tests\Fixtures\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class
{
    public function create(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('workspace_id')->default(1);
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('active')->default(true);
            $table->json('abilities')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('tags');
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::dropIfExists('tag_user');
        Schema::create('tag_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('user_id');
        });

        Schema::dropIfExists('job_batches');
        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        $runs = require dirname(__DIR__, 3).'/vendor/datawell/datawell/database/migrations/create_datawell_action_runs_table.php';
        $runs->up();
    }

    public function seed(): void
    {
        $tags = collect(['Urgent', 'Legal', 'Internal'])
            ->mapWithKeys(fn (string $name): array => [$name => Tag::query()->create(['name' => $name])->id]);

        $anna = User::query()->create(['name' => 'Anna', 'email' => 'anna@acme.test', 'active' => true]);
        $anna->tags()->attach([$tags['Urgent'], $tags['Legal']]);

        $ben = User::query()->create(['name' => 'Ben', 'email' => 'ben@acme.test', 'active' => true]);
        $ben->tags()->attach([$tags['Internal']]);

        User::query()->create(['name' => 'Cara', 'email' => null, 'active' => false]);
    }
};
