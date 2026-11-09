<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use IntrAgile\Changelog\LocaleResolver;
use IntrAgile\Changelog\Tests\TestCase;

final class LocaleResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['changelog.locales' => ['en', 'hu'], 'changelog.base_locale' => 'en']);
    }

    private function resolve(Request $request): string
    {
        return app(LocaleResolver::class)->resolve($request);
    }

    public function test_query_wins_over_the_header(): void
    {
        $request = Request::create('/x', 'GET', ['locale' => 'en'], server: ['HTTP_ACCEPT_LANGUAGE' => 'hu']);

        $this->assertSame('en', $this->resolve($request));
    }

    public function test_accept_language_primary_tag_is_used(): void
    {
        $request = Request::create('/x', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'hu-HU,hu;q=0.9,en;q=0.8']);

        $this->assertSame('hu', $this->resolve($request));
    }

    public function test_first_allowed_language_in_the_header_wins(): void
    {
        $request = Request::create('/x', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'de,fr;q=0.9,hu;q=0.8']);

        $this->assertSame('hu', $this->resolve($request));
    }

    public function test_disallowed_header_language_falls_back_to_base(): void
    {
        $request = Request::create('/x', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,fr;q=0.9']);

        $this->assertSame('en', $this->resolve($request));
    }

    public function test_no_hints_resolve_to_base(): void
    {
        $this->assertSame('en', $this->resolve(Request::create('/x', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => ''])));
    }

    public function test_unknown_locale_falls_back_to_base(): void
    {
        $request = Request::create('/x', 'GET', ['locale' => '../../etc']);

        $this->assertSame('en', $this->resolve($request));
    }

    public function test_non_string_query_falls_back_to_base(): void
    {
        $request = Request::create('/x', 'GET', ['locale' => ['hu']]);

        $this->assertSame('en', $this->resolve($request));
    }

    public function test_app_locale_is_used_when_nothing_else_matches(): void
    {
        App::setLocale('hu');

        $this->assertSame('hu', $this->resolve(Request::create('/x', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => ''])));
    }

    public function test_header_wins_over_app_locale(): void
    {
        App::setLocale('hu');
        $request = Request::create('/x', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        $this->assertSame('en', $this->resolve($request));
    }

    public function test_disallowed_app_locale_falls_back_to_base(): void
    {
        App::setLocale('de');

        $this->assertSame('en', $this->resolve(Request::create('/x', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => ''])));
    }

    public function test_disallowed_query_falls_through_to_the_header(): void
    {
        $request = Request::create('/x', 'GET', ['locale' => 'xx'], server: ['HTTP_ACCEPT_LANGUAGE' => 'hu']);

        $this->assertSame('hu', $this->resolve($request));
    }
}
