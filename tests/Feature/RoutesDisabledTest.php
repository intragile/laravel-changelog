<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Feature;

use Illuminate\Support\Facades\Route;
use IntrAgile\Changelog\Facades\Changelog;
use IntrAgile\Changelog\Tests\TestCase;

final class RoutesDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('changelog.routes.enabled', false);
        $app['config']->set('changelog.access', 'public');
        $app['config']->set('changelog.path', __DIR__.'/../Fixtures/intrawatch-CHANGELOG.md');
    }

    public function test_nothing_is_registered_until_the_host_asks(): void
    {
        $this->getJson('/changelog')->assertNotFound();

        Route::middleware('api')->prefix('api/v1')->group(static fn () => Changelog::routes());

        $this->getJson('/api/v1/changelog')->assertOk();
        $this->getJson('/api/v1/changelog/latest')->assertOk()->assertJsonPath('data.version', '1.2.0');
    }
}
