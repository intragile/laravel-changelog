<?php

declare(strict_types=1);

namespace IntrAgile\Changelog;

use Illuminate\Support\ServiceProvider;

final class ChangelogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/changelog.php', 'changelog');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/changelog.php' => config_path('changelog.php'),
            ], 'changelog-config');
        }
    }
}
