<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Unit;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use IntrAgile\Changelog\ChangelogRepository;
use IntrAgile\Changelog\Facades\Changelog;
use IntrAgile\Changelog\LocalizedRelease;
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
        File::deleteDirectory($this->dir.'/cache');
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

    public function test_cached_reads_work_when_the_store_refuses_to_unserialize_objects(): void
    {
        $this->useFileStoreWithoutObjects();
        $this->base("## [Unreleased]\n\n### Added\n\n- Wip\n\n");

        foreach ([1, 2] as $call) {
            $releases = $this->repo()->releases('en');

            $this->assertSame(LocalizedRelease::class, get_class($releases[0]), 'call '.$call);
            $this->assertSame(['1.1.0', '1.0.0'], array_map(fn ($r) => $r->release->version, $releases), 'call '.$call);
            $this->assertSame('New thing', $releases[0]->release->groups[0]->items[0]);
            $this->assertSame('Added', $releases[0]->release->groups[0]->name);
            $this->assertSame('2026-02-01', $releases[0]->release->date);
            $this->assertSame('en', $releases[0]->locale);
            $this->assertFalse($releases[0]->fallback);

            $this->assertSame('1.0.0', $this->repo()->find('v1.0.0', 'en')?->release->version, 'call '.$call);
            $this->assertSame('1.1.0', $this->repo()->latest('en')?->release->version, 'call '.$call);
        }

        $files = File::allFiles($this->dir.'/cache');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertStringNotContainsString('O:', substr($file->getContents(), 10), 'the cache entry holds no serialized object');
        }
    }

    public function test_translated_and_fallback_releases_survive_the_cache(): void
    {
        $this->useFileStoreWithoutObjects();
        $this->base();
        file_put_contents($this->dir.'/CHANGELOG.hu.md', "# Changelog\n\n## [1.0.0] - 2026-01-01\n\nÖsszefoglaló.\n\n### Admin felület\n\nBevezető.\n\n- Első\n");

        $this->repo()->releases('hu');
        $releases = $this->repo()->releases('hu');

        $this->assertTrue($releases[0]->fallback);
        $this->assertSame('en', $releases[0]->locale);
        $this->assertFalse($releases[1]->fallback);
        $this->assertSame('hu', $releases[1]->locale);
        $this->assertSame('Összefoglaló.', $releases[1]->release->summary);
        $this->assertSame('Bevezető.', $releases[1]->release->groups[0]->summary);
        $this->assertSame(['Első'], $releases[1]->release->groups[0]->items);
    }

    public function test_rendered_markdown_is_cached_with_the_parse(): void
    {
        $this->useFileStoreWithoutObjects();
        $this->base();

        $this->repo()->releases('en');
        $release = $this->repo()->releases('en')[0];

        $this->assertSame(
            ['summary_html' => null, 'groups' => [['summary_html' => null, 'items' => [['html' => '<p>New thing</p>', 'text' => 'New thing']]]]],
            $this->repo()->rendered($release),
        );
    }

    public function test_cache_entries_expire_and_carry_a_schema_version(): void
    {
        $this->base();
        config(['changelog.cache.store' => 'array']);

        /** @var list<KeyWritten> $written */
        $written = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written): void {
            $written[] = $event;
        });

        $this->repo()->releases('en');

        $this->assertCount(1, $written);
        $this->assertStringStartsWith('changelog:v'.ChangelogRepository::CACHE_VERSION.':en:', $written[0]->key);
        $this->assertSame(86400, $written[0]->seconds);
        $this->assertIsArray($written[0]->value);
    }

    public function test_a_wrong_shape_cache_entry_is_rebuilt_instead_of_failing(): void
    {
        $this->base();
        config(['changelog.cache.store' => 'array']);

        /** @var list<string> $keys */
        $keys = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$keys): void {
            $keys[] = $event->key;
        });
        $this->repo()->releases('en');
        $this->assertCount(1, $keys);

        foreach ([
            [['version' => '1.1.0']],
            [['version' => 1, 'date' => null, 'url' => null, 'unreleased' => false, 'yanked' => false, 'summary' => null, 'groups' => [], 'locale' => 'en', 'fallback' => false, 'rendered' => ['summary_html' => null, 'groups' => []]]],
            ['not-a-release'],
        ] as $corrupt) {
            Cache::store('array')->put($keys[0], $corrupt, 60);

            $this->assertSame(['1.1.0', '1.0.0'], $this->versionsOf($this->repo()->releases('en')));
            $rewritten = Cache::store('array')->get($keys[0]);
            $this->assertIsArray($rewritten);
            $this->assertIsArray($rewritten[0] ?? null);
            $this->assertSame('1.1.0', $rewritten[0]['version'] ?? null);
        }
    }

    public function test_versions_is_an_alias_of_releases(): void
    {
        $this->base("## [Unreleased]\n\n### Added\n\n- Wip\n\n");

        $this->assertSame(['1.1.0', '1.0.0'], $this->versionsOf($this->repo()->versions('en')));
        $this->assertSame($this->versionsOf($this->repo()->releases('en')), $this->versionsOf(Changelog::versions('en')));

        config(['changelog.show_unreleased' => true]);
        $this->assertSame(['Unreleased', '1.1.0', '1.0.0'], $this->versionsOf(Changelog::versions('en')));
    }

    /**
     * @param  list<LocalizedRelease>  $releases
     * @return list<string>
     */
    private function versionsOf(array $releases): array
    {
        return array_map(static fn (LocalizedRelease $r): string => $r->release->version, $releases);
    }

    private function useFileStoreWithoutObjects(): void
    {
        config([
            'cache.serializable_classes' => false,
            'cache.stores.file.path' => $this->dir.'/cache',
            'changelog.cache.store' => 'file',
        ]);
        app('cache')->forgetDriver('file');
    }
}
