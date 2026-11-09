<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use IntrAgile\Changelog\Tests\TestCase;

final class ChangelogEndpointTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/changelog-http-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        copy(__DIR__.'/../Fixtures/intrawatch-CHANGELOG.md', $this->dir.'/CHANGELOG.md');
        file_put_contents($this->dir.'/CHANGELOG.hu.md', <<<'MD'
        # Valtozasnaplo

        ## [1.2.0] - 2026-11-09

        Elo csoportok.

        ### Admin felulet

        - Magyar elem.

        MD);

        config([
            'changelog.path' => $this->dir.'/CHANGELOG.md',
            'changelog.locales' => ['en', 'hu'],
            'changelog.access' => 'public',
            'changelog.cache.store' => 'array',
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

    public function test_index_lists_releases(): void
    {
        $response = $this->getJson('/changelog');

        $response->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.version', '1.2.0')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('fallback', false);

        $html = $response->json('data.0.groups.0.items.0.html');
        $this->assertIsString($html);
        $this->assertStringStartsWith('<p>', $html);
        $this->assertIsString($response->json('data.0.groups.0.items.0.text'));
        $group = $response->json('data.0.groups.0');
        $this->assertIsArray($group);
        $this->assertArrayHasKey('summary_html', $group);
        $summary = $response->json('data.0.summary_html');
        $this->assertIsString($summary);
        $this->assertStringStartsWith('<p>', $summary);
    }

    public function test_index_paginates(): void
    {
        $this->getJson('/changelog?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', '1.1.0')
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_page_beyond_the_end_is_empty(): void
    {
        $this->getJson('/changelog?per_page=2&page=9')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 4);
    }

    public function test_invalid_pagination_is_422(): void
    {
        $this->getJson('/changelog?per_page=0')->assertStatus(422);
        $this->getJson('/changelog?per_page=101')->assertStatus(422);
        $this->getJson('/changelog?page=0')->assertStatus(422);
        $this->getJson('/changelog?page=abc')->assertStatus(422);
    }

    public function test_huge_page_is_rejected_not_a_500(): void
    {
        $this->getJson('/changelog?page=9223372036854775807')->assertStatus(422);
        $this->getJson('/changelog?page=1000000')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_validation_failure_is_json_without_accept_header(): void
    {
        $this->get('/changelog?per_page=0')
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_not_found_is_json_without_accept_header(): void
    {
        $this->get('/changelog/9.9.9')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_host_exception_rendering_is_honoured(): void
    {
        $handler = app(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);
        $handler->renderable(static fn (ValidationException $e) => response()->json(['problem' => true], 422));

        $this->get('/changelog?per_page=0')
            ->assertStatus(422)
            ->assertExactJson(['problem' => true]);
    }

    public function test_latest_returns_the_newest_release(): void
    {
        $this->getJson('/changelog/latest')
            ->assertOk()
            ->assertJsonPath('data.version', '1.2.0');
    }

    public function test_show_returns_one_release(): void
    {
        $this->getJson('/changelog/1.1.1')
            ->assertOk()
            ->assertJsonPath('data.version', '1.1.1')
            ->assertJsonPath('data.locale', 'en');
    }

    public function test_unknown_version_is_404(): void
    {
        $this->getJson('/changelog/9.9.9')->assertNotFound();
    }

    public function test_version_never_reaches_the_filesystem(): void
    {
        $this->getJson('/changelog/..%2F..%2Fetc%2Fpasswd')->assertNotFound();
        $this->getJson('/changelog/CHANGELOG.hu')->assertNotFound();
    }

    public function test_latest_is_404_without_releases(): void
    {
        file_put_contents($this->dir.'/CHANGELOG.md', "# Changelog\n");

        $this->getJson('/changelog/latest')->assertNotFound();
    }

    public function test_translated_release_is_served_with_locale(): void
    {
        $this->getJson('/changelog?locale=hu')
            ->assertOk()
            ->assertJsonPath('locale', 'hu')
            ->assertJsonPath('fallback', true)
            ->assertJsonPath('data.0.locale', 'hu')
            ->assertJsonPath('data.0.fallback', false)
            ->assertJsonPath('data.0.groups.0.name', 'Admin felulet')
            ->assertJsonPath('data.1.locale', 'en')
            ->assertJsonPath('data.1.fallback', true);
    }

    public function test_accept_language_selects_the_locale(): void
    {
        $this->getJson('/changelog/1.2.0', ['Accept-Language' => 'hu-HU,hu;q=0.9'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'hu');
    }

    public function test_group_summary_is_rendered(): void
    {
        file_put_contents($this->dir.'/CHANGELOG.md', <<<'MD'
        # Changelog

        ## [1.0.0] - 2026-01-01

        ### Added

        Intro **text**.

        - One

        MD);

        $this->getJson('/changelog/1.0.0')
            ->assertOk()
            ->assertJsonPath('data.groups.0.summary_html', '<p>Intro <strong>text</strong>.</p>');
    }

    public function test_repeated_requests_work_when_the_cache_refuses_objects(): void
    {
        config([
            'cache.serializable_classes' => false,
            'cache.stores.file.path' => $this->dir.'/cache',
            'changelog.cache.store' => 'file',
        ]);
        app('cache')->forgetDriver('file');

        try {
            foreach ([1, 2] as $call) {
                $this->getJson('/changelog?locale=hu')
                    ->assertOk()
                    ->assertJsonPath('data.0.version', '1.2.0')
                    ->assertJsonPath('data.0.summary_html', '<p>Elo csoportok.</p>')
                    ->assertJsonPath('data.0.groups.0.items.0', ['html' => '<p>Magyar elem.</p>', 'text' => 'Magyar elem.']);
                $this->getJson('/changelog/latest')->assertOk()->assertJsonPath('data.version', '1.2.0');
            }
        } finally {
            File::deleteDirectory($this->dir.'/cache');
        }
    }
}
