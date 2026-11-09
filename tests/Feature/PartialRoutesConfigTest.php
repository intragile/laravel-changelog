<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Feature;

use IntrAgile\Changelog\Tests\TestCase;

final class PartialRoutesConfigTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // A published config that overrides only part of `routes`: the shallow
        // config merge drops every other key of the array.
        $app['config']->set('changelog', [
            'routes' => ['prefix' => 'whats-new'],
            'access' => 'public',
            'path' => __DIR__.'/../Fixtures/intrawatch-CHANGELOG.md',
        ]);
    }

    public function test_routes_stay_enabled_when_the_override_omits_the_flag(): void
    {
        $this->getJson('/whats-new/latest')->assertOk()->assertJsonPath('data.version', '1.2.0');
    }

    public function test_the_api_middleware_group_is_still_applied(): void
    {
        $route = app('router')->getRoutes()->getByName('changelog.latest');

        $this->assertNotNull($route);
        $this->assertContains('api', $route->gatherMiddleware());
    }
}
