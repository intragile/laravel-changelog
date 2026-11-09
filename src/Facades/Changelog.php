<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Facades;

use Illuminate\Support\Facades\Facade;
use IntrAgile\Changelog\ChangelogRepository;
use IntrAgile\Changelog\LocalizedRelease;
use IntrAgile\Changelog\Parsing\ParsedChangelog;

/**
 * @method static list<LocalizedRelease> releases(string $locale)
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
        // Filled in by the HTTP layer.
    }

    protected static function getFacadeAccessor(): string
    {
        return ChangelogRepository::class;
    }
}
