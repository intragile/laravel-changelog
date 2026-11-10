<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Facades;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use IntrAgile\Changelog\ChangelogRepository;
use IntrAgile\Changelog\Http\Controllers\ChangelogController;
use IntrAgile\Changelog\Http\Middleware\EnsureChangelogAccess;
use IntrAgile\Changelog\Http\Middleware\ForceJsonResponse;
use IntrAgile\Changelog\LocalizedRelease;
use IntrAgile\Changelog\Parsing\ParsedChangelog;

/**
 * @method static list<LocalizedRelease> releases(string $locale) Base-file order; honours show_unreleased.
 * @method static list<LocalizedRelease> versions(string $locale) Alias of releases().
 * @method static LocalizedRelease|null find(string $version, string $locale)
 * @method static LocalizedRelease|null latest(string $locale)
 * @method static ParsedChangelog parsed(?string $locale = null) Null or the base locale parses the base file; a disallowed locale yields an error issue.
 * @method static string|null translationPath(string $locale) Null for the base locale or a locale outside changelog.locales.
 *
 * @see ChangelogRepository
 */
final class Changelog extends Facade
{
    /**
     * Registers the changelog HTTP routes.
     */
    public static function routes(): void
    {
        Route::prefix(self::config('prefix', 'changelog'))
            ->middleware([ForceJsonResponse::class, EnsureChangelogAccess::class])
            ->name(self::config('name', 'changelog.'))
            ->group(static function (): void {
                Route::get('/', [ChangelogController::class, 'index'])->name('index');
                Route::get('/latest', [ChangelogController::class, 'latest'])->name('latest');
                Route::get('/{version}', [ChangelogController::class, 'show'])
                    ->where('version', '[0-9A-Za-z.+-]+')
                    ->name('show');
            });
    }

    private static function config(string $key, string $default): string
    {
        $value = Config::get('changelog.routes.'.$key, $default);

        return is_string($value) ? trim($value, $key === 'prefix' ? '/' : '') : $default;
    }

    protected static function getFacadeAccessor(): string
    {
        return ChangelogRepository::class;
    }
}
