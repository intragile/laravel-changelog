<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Feature;

use Illuminate\Testing\PendingCommand;
use IntrAgile\Changelog\Tests\TestCase;

final class CheckCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/changelog-check-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        config([
            'changelog.path' => $this->dir.'/CHANGELOG.md',
            'changelog.locales' => ['en', 'hu'],
            'changelog.base_locale' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    private function write(string $name, string $body): void
    {
        file_put_contents($this->dir.'/'.$name, $body);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function check(string $command, array $options = []): PendingCommand
    {
        $pending = $this->artisan($command, $options);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function release(string $version, string $date): string
    {
        return "## [{$version}] - {$date}\n\n### Added\n\n- Something\n\n";
    }

    public function test_check_passes_on_intrawatch_fixture(): void
    {
        copy(__DIR__.'/../Fixtures/intrawatch-CHANGELOG.md', $this->dir.'/CHANGELOG.md');

        $this->check('changelog:check')->assertExitCode(0);
    }

    public function test_check_fails_on_malformed_heading(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-01')."## Version two\n\n### Added\n\n- X\n");

        $this->check('changelog:check')
            ->expectsOutputToContain(':9:')
            ->assertExitCode(1);
    }

    public function test_missing_base_file_fails(): void
    {
        $this->check('changelog:check')->assertExitCode(1);
    }

    public function test_zero_releases_fails(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\nNothing yet.\n");

        $this->check('changelog:check')->assertExitCode(1);
    }

    public function test_missing_translated_version_is_error(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n".$this->release('1.1.0', '2026-02-01').$this->release('1.0.0', '2026-01-01'));
        $this->write('CHANGELOG.hu.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-01'));

        $this->check('changelog:check')
            ->expectsOutputToContain('version 1.1.0 is missing from the "hu" translation')
            ->assertExitCode(1);
    }

    public function test_missing_translation_file_is_skipped(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-01'));

        $this->check('changelog:check')->assertExitCode(0);
    }

    public function test_missing_unreleased_in_translation_is_fine(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Soon\n\n".$this->release('1.0.0', '2026-01-01'));
        $this->write('CHANGELOG.hu.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-01'));

        $this->check('changelog:check')->assertExitCode(0);
    }

    public function test_extra_translated_version_is_error(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-01'));
        $this->write('CHANGELOG.hu.md', "# Changelog\n\n".$this->release('1.1.0', '2026-02-01').$this->release('1.0.0', '2026-01-01'));

        $this->check('changelog:check')
            ->expectsOutputToContain('1.1.0')
            ->assertExitCode(1);
    }

    public function test_date_mismatch_is_error(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-01'));
        $this->write('CHANGELOG.hu.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-02'));

        $this->check('changelog:check')
            ->expectsOutputToContain('2026-01-02')
            ->assertExitCode(1);
    }

    public function test_warnings_do_not_fail(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n## [1.0.0] - 2026-01-01\n\n### Fixed\n\n- One\n\nA stray paragraph.\n\n- Two\n");

        $this->check('changelog:check')
            ->expectsOutputToContain('paragraph')
            ->assertExitCode(0);
    }

    public function test_requested_locale_without_file_fails(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-01'));

        $this->check('changelog:check', ['--locale' => ['hu']])->assertExitCode(1);
    }

    public function test_requested_locale_not_allowed_fails(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-01'));

        $this->check('changelog:check', ['--locale' => ['de']])->assertExitCode(1);
        $this->check('changelog:check', ['--locale' => ['en']])->assertExitCode(1);
    }

    public function test_locale_option_narrows_the_check(): void
    {
        $this->write('CHANGELOG.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-01'));
        $this->write('CHANGELOG.hu.md', "# Changelog\n\n".$this->release('1.0.0', '2026-01-02'));

        $this->check('changelog:check', ['--locale' => ['hu']])->assertExitCode(1);
    }
}
