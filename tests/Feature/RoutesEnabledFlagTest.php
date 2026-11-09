<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Feature;

use IntrAgile\Changelog\Tests\TestCase;

final class RoutesEnabledFlagTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // An env-driven flag arrives as a string.
        $app['config']->set('changelog.routes.enabled', 'false');
        $app['config']->set('changelog.access', 'public');
        $app['config']->set('changelog.path', __DIR__.'/../Fixtures/intrawatch-CHANGELOG.md');
    }

    public function test_a_false_string_disables_the_routes(): void
    {
        $this->getJson('/changelog/latest')->assertNotFound();
        $this->assertNull(app('router')->getRoutes()->getByName('changelog.latest'));
    }
}
