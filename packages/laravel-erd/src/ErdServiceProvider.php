<?php

namespace Makermind\Erd4dev;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Makermind\Erd4dev\Commands\InstallCommand;
use Makermind\Erd4dev\Http\Middleware\AuthorizeErd;
use Makermind\Erd4dev\Livewire\ProjectDashboard;
use Makermind\Erd4dev\Livewire\RelationalBoard;
use Makermind\Erd4dev\Livewire\SchemaBoard;

class ErdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/erd.php', 'erd');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'erd');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'erd');

        Livewire::component('erd.project-dashboard', ProjectDashboard::class);
        Livewire::component('erd.schema-board', SchemaBoard::class);
        Livewire::component('erd.relational-board', RelationalBoard::class);
        // Recheck access on Livewire update requests, not only the initial page.
        Livewire::addPersistentMiddleware([AuthorizeErd::class]);

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
            $this->publishes([__DIR__.'/../config/erd.php' => config_path('erd.php')], 'erd-config');
            // Keep a fixed migration filename so repeated installs do not create duplicates.
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'erd-migrations');
            $this->publishes([
                __DIR__.'/../dist' => public_path('vendor/erd'),
                __DIR__.'/../resources/examples' => public_path('vendor/erd/examples'),
            ], 'erd-assets');
        }
    }
}
