<?php

declare(strict_types=1);

namespace IntrAgile\Changelog;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use IntrAgile\Changelog\Console\CheckCommand;
use IntrAgile\Changelog\Facades\Changelog;

final class ChangelogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/changelog.php', 'changelog');

        $this->app->singleton(ChangelogRepository::class);
    }

    public function boot(): void
    {
        if (config('changelog.routes.enabled') === true && ! $this->app->routesAreCached()) {
            $middleware = config('changelog.routes.middleware', []);

            Route::middleware(is_array($middleware) ? $middleware : [])
                ->group(static fn () => Changelog::routes());
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);

            $this->publishes([
                __DIR__.'/../config/changelog.php' => config_path('changelog.php'),
            ], 'changelog-config');
        }
    }
}
