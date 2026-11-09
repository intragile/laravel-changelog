<?php

declare(strict_types=1);

namespace IntrAgile\Changelog;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use IntrAgile\Changelog\Parsing\ChangelogParser;
use IntrAgile\Changelog\Parsing\ParsedChangelog;
use IntrAgile\Changelog\Parsing\ParseIssue;

final class ChangelogRepository
{
    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly ChangelogParser $parser,
    ) {}

    /**
     * Releases in base-file order, each from the requested locale when translated, else from the base file.
     *
     * @return list<LocalizedRelease>
     */
    public function releases(string $locale): array
    {
        $locale = $this->resolveLocale($locale);
        $base = $this->baseLocale();
        $translation = $this->translationPath($locale);

        $files = [$this->basePath()];
        if ($translation !== null) {
            $files[] = $translation;
        }

        /** @var list<LocalizedRelease> $all */
        $all = $this->store()->remember(
            $this->cacheKey($locale, $files),
            null,
            fn (): array => $this->build($locale, $translation),
        );

        if ($this->config->get('changelog.show_unreleased') === true) {
            return $all;
        }

        return array_values(array_filter($all, static fn (LocalizedRelease $r): bool => ! $r->release->unreleased));
    }

    public function find(string $version, string $locale): ?LocalizedRelease
    {
        $needle = (string) preg_replace('/^v(?=\d)/i', '', trim($version));

        foreach ($this->releases($locale) as $localized) {
            if (strcasecmp($localized->release->version, $needle) === 0) {
                return $localized;
            }
        }

        return null;
    }

    public function latest(string $locale): ?LocalizedRelease
    {
        foreach ($this->releases($locale) as $localized) {
            if (! $localized->release->unreleased) {
                return $localized;
            }
        }

        return null;
    }

    /**
     * Parses one file as-is, for validation. Null means the base file.
     */
    public function parsed(?string $locale = null): ParsedChangelog
    {
        if ($locale === null || $locale === $this->baseLocale()) {
            return $this->parseFile($this->basePath());
        }

        $path = $this->translationPath($locale);

        if ($path === null) {
            return new ParsedChangelog([], [
                new ParseIssue(0, ParseIssue::ERROR, 'Locale "'.$locale.'" is not allowed: it is not listed in changelog.locales.'),
            ]);
        }

        return $this->parseFile($path);
    }

    /**
     * Null when the locale is the base locale or is not in changelog.locales.
     */
    public function translationPath(string $locale): ?string
    {
        $allowed = $this->config->get('changelog.locales', []);

        if ($locale === $this->baseLocale() || ! is_array($allowed) || ! in_array($locale, $allowed, true)) {
            return null;
        }

        $pattern = $this->string('changelog.translation_pattern', 'CHANGELOG.{locale}.md');

        return dirname($this->basePath()).DIRECTORY_SEPARATOR.str_replace('{locale}', $locale, $pattern);
    }

    /**
     * @return list<LocalizedRelease>
     */
    private function build(string $locale, ?string $translationPath): array
    {
        $base = $this->baseLocale();
        $translated = $translationPath !== null ? $this->parseFile($translationPath) : null;

        $result = [];
        foreach ($this->parseFile($this->basePath())->releases as $release) {
            $own = $translated?->find($release->version);

            $result[] = $own !== null
                ? new LocalizedRelease($own, $locale, false)
                : new LocalizedRelease($release, $base, $locale !== $base);
        }

        return $result;
    }

    private function parseFile(string $path): ParsedChangelog
    {
        if (! is_file($path) || ! is_readable($path)) {
            return new ParsedChangelog([], [
                new ParseIssue(0, ParseIssue::ERROR, 'Changelog file is missing or unreadable: '.$path),
            ]);
        }

        $markdown = file_get_contents($path);

        return $this->parser->parse($markdown === false ? '' : $markdown);
    }

    /**
     * Only locales from the configured list ever reach a file name.
     */
    private function resolveLocale(string $locale): string
    {
        $allowed = $this->config->get('changelog.locales', []);

        return is_array($allowed) && in_array($locale, $allowed, true) ? $locale : $this->baseLocale();
    }

    private function string(string $key, string $default = ''): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function baseLocale(): string
    {
        return $this->string('changelog.base_locale', 'en');
    }

    private function basePath(): string
    {
        return $this->string('changelog.path');
    }

    /**
     * @param  list<string>  $files
     */
    private function cacheKey(string $locale, array $files): string
    {
        $parts = array_map(static function (string $file): string {
            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            $size = @filesize($file);

            return $mtime === false || $size === false ? $file.'|missing' : $file.'|'.$mtime.'|'.$size;
        }, $files);

        $prefix = $this->string('changelog.cache.prefix', 'changelog');

        return $prefix.':'.$locale.':'.sha1(implode("\n", $parts));
    }

    private function store(): CacheRepository
    {
        $store = $this->config->get('changelog.cache.store');

        return $this->cache->store(is_string($store) ? $store : null);
    }
}
