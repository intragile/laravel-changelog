<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests;

use IntrAgile\Changelog\ChangelogServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ChangelogServiceProvider::class];
    }
}
