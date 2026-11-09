<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Feature;

use Illuminate\Testing\PendingCommand;
use IntrAgile\Changelog\Tests\TestCase;

final class OwnChangelogTest extends TestCase
{
    public function test_package_changelog_passes_its_own_check(): void
    {
        config(['changelog.path' => __DIR__.'/../../CHANGELOG.md']);

        $pending = $this->artisan('changelog:check');
        $this->assertInstanceOf(PendingCommand::class, $pending);

        $pending->assertExitCode(0);
    }
}
