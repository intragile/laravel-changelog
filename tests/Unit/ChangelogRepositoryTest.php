<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Unit;

use IntrAgile\Changelog\ChangelogRepository;
use IntrAgile\Changelog\Facades\Changelog;
use IntrAgile\Changelog\Parsing\ParseIssue;
use IntrAgile\Changelog\Tests\TestCase;

final class ChangelogRepositoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/changelog-repo-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        config([
            'changelog.path' => $this->dir.'/CHANGELOG.md',
            'changelog.locales' => ['en', 'hu'],
            'changelog.base_locale' => 'en',
            'changelog.show_unreleased' => false,
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

    private function base(string $extra = ''): void
    {
        file_put_contents($this->dir.'/CHANGELOG.md', <<<MD
        # Changelog

        {$extra}## [1.1.0] - 2026-02-01

        ### Added

        - New thing

        ## [1.0.0] - 2026-01-01

        ### Added

        - First thing

        MD);
    }

    private function repo(): ChangelogRepository
    {
        return app(ChangelogRepository::class);
    }

    public function test_base_locale_reads_base_file(): void
    {
        $this->base();

        $releases = $this->repo()->releases('en');

        $this->assertSame(['1.1.0', '1.0.0'], array_map(fn ($r) => $r->release->version, $releases));
        $this->assertSame('en', $releases[0]->locale);
        $this->assertFalse($releases[0]->fallback);
    }

    public function test_translation_is_used_when_present(): void
    {
        $this->base();
        file_put_contents($this->dir.'/CHANGELOG.hu.md', "# Changelog\n\n## [1.0.0] - 2026-01-01\n\n### Admin felület\n\n- Első\n");

        $found = $this->repo()->find('1.0.0', 'hu');

        $this->assertNotNull($found);
        $this->assertSame('Admin felület', $found->release->groups[0]->name);
        $this->assertFalse($found->fallback);
        $this->assertSame('hu', $found->locale);
    }

    public function test_missing_translated_version_falls_back_per_version(): void
    {
        $this->base();
        file_put_contents($this->dir.'/CHANGELOG.hu.md', "# Changelog\n\n## [1.0.0] - 2026-01-01\n\n### Admin felület\n\n- Első\n\n## [0.9.0] - 2025-12-01\n\n### Added\n\n- extra\n");

        $releases = $this->repo()->releases('hu');

        $this->assertSame(['1.1.0', '1.0.0'], array_map(fn ($r) => $r->release->version, $releases));
        $this->assertTrue($releases[0]->fallback);
        $this->assertSame('en', $releases[0]->locale);
        $this->assertFalse($releases[1]->fallback);
        $this->assertSame('hu', $releases[1]->locale);
    }

    public function test_no_translation_file_means_all_fallback(): void
    {
        $this->base();

        $releases = $this->repo()->releases('hu');

        $this->assertCount(2, $releases);
        foreach ($releases as $r) {
            $this->assertTrue($r->fallback);
            $this->assertSame('en', $r->locale);
        }
    }

    public function test_unreleased_hidden_by_default_and_shown_when_enabled(): void
    {
        $this->base("## [Unreleased]\n\n### Added\n\n- Wip\n\n");

        $this->assertSame(['1.1.0', '1.0.0'], array_map(fn ($r) => $r->release->version, $this->repo()->releases('en')));

        config(['changelog.show_unreleased' => true]);

        $versions = array_map(fn ($r) => $r->release->version, $this->repo()->releases('en'));
        $this->assertCount(3, $versions);
        $this->assertSame('Unreleased', $versions[0]);
    }

    public function test_latest_skips_unreleased(): void
    {
        $this->base("## [Unreleased]\n\n### Added\n\n- Wip\n\n");
        config(['changelog.show_unreleased' => true]);

        $latest = $this->repo()->latest('en');

        $this->assertNotNull($latest);
        $this->assertSame('1.1.0', $latest->release->version);
    }

    public function test_cache_is_invalidated_when_file_changes(): void
    {
        $this->base();
        $path = $this->dir.'/CHANGELOG.md';

        $this->assertCount(2, $this->repo()->releases('en'));

        file_put_contents($path, "# Changelog\n\n## [2.0.0] - 2026-03-01\n\n### Added\n\n- Big\n");
        touch($path, time() + 5);
        clearstatcache();

        $versions = array_map(fn ($r) => $r->release->version, $this->repo()->releases('en'));
        $this->assertSame(['2.0.0'], $versions);
    }

    public function test_locale_outside_allowed_list_never_touches_filesystem(): void
    {
        $this->base();
        file_put_contents($this->dir.'/x.md', "# Changelog\n\n## [9.9.9] - 2026-01-01\n\n### Added\n\n- evil\n");
        config(['changelog.translation_pattern' => '../{locale}.md']);

        $releases = $this->repo()->releases('../x');

        $this->assertSame('en', $releases[0]->locale);
        $this->assertSame(['1.1.0', '1.0.0'], array_map(fn ($r) => $r->release->version, $releases));
    }

    public function test_missing_base_file_does_not_throw(): void
    {
        $this->assertSame([], $this->repo()->releases('en'));
        $this->assertNull($this->repo()->find('1.0.0', 'en'));
        $this->assertNull($this->repo()->latest('en'));

        $parsed = $this->repo()->parsed();
        $this->assertSame([], $parsed->releases);
        $this->assertSame(ParseIssue::ERROR, $parsed->issues[0]->severity);
        $this->assertStringContainsString('missing', $parsed->issues[0]->message);
    }

    public function test_facade_and_translation_path(): void
    {
        $this->base();

        $this->assertCount(2, Changelog::releases('en'));
        $this->assertSame($this->dir.'/CHANGELOG.hu.md', $this->repo()->translationPath('hu'));
        Changelog::routes();
    }

    public function test_parsed_and_translation_path_enforce_the_allowlist(): void
    {
        $this->base();
        file_put_contents($this->dir.'/x.md', "# Changelog\n\n## [9.9.9] - 2026-01-01\n\n### Added\n\n- evil\n");
        file_put_contents($this->dir.'/CHANGELOG.hu.md', "# Changelog\n\n## [1.0.0] - 2026-01-01\n\n### Admin felület\n\n- Első\n");
        config(['changelog.translation_pattern' => '../{locale}.md']);

        $parsed = $this->repo()->parsed('../x');
        $this->assertSame([], $parsed->releases);
        $this->assertSame(ParseIssue::ERROR, $parsed->issues[0]->severity);
        $this->assertStringContainsString('../x', $parsed->issues[0]->message);

        $this->assertNull($this->repo()->translationPath('../x'));
        $this->assertNull($this->repo()->translationPath('en'));

        config(['changelog.translation_pattern' => 'CHANGELOG.{locale}.md']);
        $this->assertSame($this->dir.'/CHANGELOG.hu.md', $this->repo()->translationPath('hu'));
        $this->assertSame('1.0.0', $this->repo()->parsed('hu')->releases[0]->version);
        $this->assertCount(2, $this->repo()->parsed('en')->releases);
    }
}
