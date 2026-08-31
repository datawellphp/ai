<?php

declare(strict_types=1);

namespace Datawell\Ai;

use Datawell\Ai\Bridge\Calls;
use Datawell\Ai\Bridge\ToolSet;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/datawell-ai.php', 'datawell-ai');

        $this->app->singleton(ToolSet::class);
        $this->app->singleton(Calls::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/datawell-ai.php' => config_path('datawell-ai.php'),
        ], ['datawell-ai', 'datawell-ai-config']);
    }
}
