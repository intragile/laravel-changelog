<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use IntrAgile\Changelog\ChangelogServiceProvider;
use IntrAgile\Changelog\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged_with_defaults(): void
    {
        $this->assertSame('authenticated', config('changelog.access'));
        $this->assertFalse(config('changelog.show_unreleased'));
        $this->assertSame(20, config('changelog.per_page'));
    }

    public function test_config_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(ChangelogServiceProvider::class, 'changelog-config');

        $sources = array_map(realpath(...), array_keys($paths));

        $this->assertContains(realpath(__DIR__.'/../../config/changelog.php'), $sources);
        $this->assertContains(config_path('changelog.php'), array_values($paths));
    }
}
