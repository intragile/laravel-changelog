<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use IntrAgile\Changelog\Facades\Changelog;
use IntrAgile\Changelog\Tests\TestCase;

final class AccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['changelog.path' => __DIR__.'/../Fixtures/intrawatch-CHANGELOG.md']);
    }

    public function test_disabled_is_404(): void
    {
        config(['changelog.access' => 'disabled']);

        $this->getJson('/changelog')->assertNotFound();
        $this->getJson('/changelog/latest')->assertNotFound();
    }

    public function test_authenticated_rejects_guests_with_401(): void
    {
        config(['changelog.access' => 'authenticated']);

        $this->getJson('/changelog')->assertUnauthorized();
    }

    public function test_authenticated_admits_users(): void
    {
        config(['changelog.access' => 'authenticated']);

        $this->actingAs(new GenericUser(['id' => 1]))->getJson('/changelog')->assertOk();
    }

    public function test_public_admits_guests(): void
    {
        config(['changelog.access' => 'public']);

        $this->getJson('/changelog')->assertOk();
    }

    public function test_gate_denying_a_user_is_403(): void
    {
        config(['changelog.access' => 'view-changelog']);
        Gate::define('view-changelog', static fn (): bool => false);

        $this->actingAs(new GenericUser(['id' => 1]))->getJson('/changelog')->assertForbidden();
    }

    public function test_gate_allowing_a_user_is_200(): void
    {
        config(['changelog.access' => 'view-changelog']);
        Gate::define('view-changelog', static fn (): bool => true);

        $this->actingAs(new GenericUser(['id' => 1]))->getJson('/changelog')->assertOk();
    }

    public function test_gate_denying_a_guest_is_401(): void
    {
        config(['changelog.access' => 'view-changelog']);
        Gate::define('view-changelog', static fn (): bool => false);

        $this->getJson('/changelog')->assertUnauthorized();
    }

    public function test_gate_is_consulted_for_guests(): void
    {
        config(['changelog.access' => 'view-changelog']);
        Gate::define('view-changelog', static fn (?object $user = null): bool => true);

        $this->getJson('/changelog')->assertOk();
    }

    public function test_undefined_gate_denies(): void
    {
        config(['changelog.access' => 'no-such-gate']);

        $this->actingAs(new GenericUser(['id' => 1]))->getJson('/changelog')->assertForbidden();
    }

    public function test_routes_are_named(): void
    {
        $this->assertSame('/changelog', parse_url(route('changelog.index'), PHP_URL_PATH));
        $this->assertSame('/changelog/latest', parse_url(route('changelog.latest'), PHP_URL_PATH));
        $this->assertSame('/changelog/1.0.0', parse_url(route('changelog.show', ['version' => '1.0.0']), PHP_URL_PATH));
    }

    public function test_host_can_register_routes_in_its_own_group(): void
    {
        config(['changelog.access' => 'public']);

        Route::middleware('api')->prefix('api/v1')->group(static fn () => Changelog::routes());

        $this->getJson('/api/v1/changelog')->assertOk();
    }
}
