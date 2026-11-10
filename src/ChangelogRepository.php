<?php

declare(strict_types=1);

namespace IntrAgile\Changelog;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use IntrAgile\Changelog\Markdown\SafeMarkdown;
use IntrAgile\Changelog\Parsing\ChangelogParser;
use IntrAgile\Changelog\Parsing\ParsedChangelog;
use IntrAgile\Changelog\Parsing\ParseIssue;
use IntrAgile\Changelog\Parsing\Release;
use IntrAgile\Changelog\Parsing\ReleaseGroup;
use WeakMap;

/**
 * @phpstan-type RenderedRelease array{summary_html: string|null, groups: list<array{summary_html: string|null, items: list<array{html: string, text: string}>}>}
 * @phpstan-type CachedGroup array{name: string, items: list<string>, summary: string|null}
 * @phpstan-type CachedRelease array{version: string, date: string|null, url: string|null, unreleased: bool, yanked: bool, summary: string|null, groups: list<CachedGroup>, locale: string, fallback: bool, rendered: RenderedRelease}
 */
final class ChangelogRepository
{
    /**
     * Part of every cache key. Bump it whenever the cached array shape changes,
     * so a deploy never reads entries written by an older version.
     */
    public const CACHE_VERSION = 2;

    /**
     * Cache entries are keyed on file fingerprints, so they never go stale;
     * the TTL only stops old fingerprints from piling up after deploys.
     */
    private const CACHE_TTL = 86400;

    /** @var WeakMap<LocalizedRelease, RenderedRelease> */
    private WeakMap $rendered;

    /**
     * @internal Resolve the repository from the container or use the facade.
     */
    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly ChangelogParser $parser,
        private readonly SafeMarkdown $markdown = new SafeMarkdown,
    ) {
        $this->rendered = new WeakMap;
    }

    /**
     * Releases in base-file order, each from the requested locale when translated, else from the base file.
     *
     * @return list<LocalizedRelease>
     */
    public function releases(string $locale): array
    {
        $locale = $this->resolveLocale($locale);
        $translation = $this->translationPath($locale);

        $files = [$this->basePath()];
        if ($translation !== null) {
            $files[] = $translation;
        }

        // Only arrays and scalars go into the cache: stores may refuse to
        // unserialize objects (cache.serializable_classes = false).
        /** @var mixed $cached */
        $cached = $this->store()->remember(
            $this->cacheKey($locale, $files),
            self::CACHE_TTL,
            fn (): array => $this->build($locale, $translation),
        );

        // A store that hands back something else (an entry written by a foreign writer or truncated) is
        // treated as a miss: rebuild and overwrite it rather than fail on the request.
        if (! is_array($cached) || ! $this->isCachedReleaseList($cached)) {
            $cached = $this->build($locale, $translation);
            $this->store()->put($this->cacheKey($locale, $files), $cached, self::CACHE_TTL);
        }

        $all = $this->hydrate($cached);

        if ($this->config->get('changelog.show_unreleased') === true) {
            return $all;
        }

        return array_values(array_filter($all, static fn (LocalizedRelease $r): bool => ! $r->release->unreleased));
    }

    /**
     * Alias of releases(), under the name the design uses; there is no difference in behaviour.
     *
     * @return list<LocalizedRelease>
     */
    public function versions(string $locale): array
    {
        return $this->releases($locale);
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
     * The rendered HTML and text of a release returned by this repository.
     *
     * @internal Used by the JSON resource; the shape may change in any release.
     *
     * @return RenderedRelease
     */
    public function rendered(LocalizedRelease $localized): array
    {
        return $this->rendered[$localized] ?? $this->render($localized->release);
    }

    /**
     * @return list<CachedRelease>
     */
    private function build(string $locale, ?string $translationPath): array
    {
        $base = $this->baseLocale();
        $translated = $translationPath !== null ? $this->parseFile($translationPath) : null;

        $result = [];
        foreach ($this->parseFile($this->basePath())->releases as $release) {
            $own = $translated?->find($release->version);

            $result[] = $own !== null
                ? $this->normalise($own, $locale, false)
                : $this->normalise($release, $base, $locale !== $base);
        }

        return $result;
    }

    /**
     * @return CachedRelease
     */
    private function normalise(Release $release, string $locale, bool $fallback): array
    {
        return [
            'version' => $release->version,
            'date' => $release->date,
            'url' => $release->url,
            'unreleased' => $release->unreleased,
            'yanked' => $release->yanked,
            'summary' => $release->summary,
            'groups' => array_map(static fn (ReleaseGroup $group): array => [
                'name' => $group->name,
                'items' => $group->items,
                'summary' => $group->summary,
            ], $release->groups),
            'locale' => $locale,
            'fallback' => $fallback,
            'rendered' => $this->render($release),
        ];
    }

    /**
     * @param  array<mixed>  $cached
     *
     * @phpstan-assert-if-true list<CachedRelease> $cached
     */
    private function isCachedReleaseList(array $cached): bool
    {
        if (! array_is_list($cached)) {
            return false;
        }

        foreach ($cached as $data) {
            if (! is_array($data)
                || ! is_string($data['version'] ?? null)
                || ! $this->isNullableString($data, 'date')
                || ! $this->isNullableString($data, 'url')
                || ! is_bool($data['unreleased'] ?? null)
                || ! is_bool($data['yanked'] ?? null)
                || ! $this->isNullableString($data, 'summary')
                || ! is_string($data['locale'] ?? null)
                || ! is_bool($data['fallback'] ?? null)
                || ! $this->isList($data['groups'] ?? null)
                || ! is_array($data['rendered'] ?? null)
                || ! $this->isNullableString($data['rendered'], 'summary_html')
                || ! $this->isList($data['rendered']['groups'] ?? null)
                || count($data['rendered']['groups']) !== count($data['groups'])) {
                return false;
            }

            foreach ($data['groups'] as $index => $group) {
                $rendered = $data['rendered']['groups'][$index] ?? null;

                if (! is_array($group)
                    || ! is_string($group['name'] ?? null)
                    || ! $this->isNullableString($group, 'summary')
                    || ! $this->isList($group['items'] ?? null)
                    || ! is_array($rendered)
                    || ! $this->isNullableString($rendered, 'summary_html')
                    || ! $this->isList($rendered['items'] ?? null)
                    || count($rendered['items']) !== count($group['items'])) {
                    return false;
                }

                foreach ($group['items'] as $item) {
                    if (! is_string($item)) {
                        return false;
                    }
                }

                foreach ($rendered['items'] as $item) {
                    if (! is_array($item) || ! is_string($item['html'] ?? null) || ! is_string($item['text'] ?? null)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @phpstan-assert-if-true list<mixed> $value
     */
    private function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function isNullableString(array $data, string $key): bool
    {
        return array_key_exists($key, $data) && ($data[$key] === null || is_string($data[$key]));
    }

    /**
     * @param  list<CachedRelease>  $cached
     * @return list<LocalizedRelease>
     */
    private function hydrate(array $cached): array
    {
        $result = [];

        foreach ($cached as $data) {
            $release = new Release(
                $data['version'],
                $data['date'],
                $data['url'],
                $data['unreleased'],
                $data['yanked'],
                $data['summary'],
                array_map(static fn (array $group): ReleaseGroup => new ReleaseGroup($group['name'], $group['items'], $group['summary']), $data['groups']),
            );

            $localized = new LocalizedRelease($release, $data['locale'], $data['fallback']);
            $this->rendered[$localized] = $data['rendered'];
            $result[] = $localized;
        }

        return $result;
    }

    /**
     * @return RenderedRelease
     */
    private function render(Release $release): array
    {
        $groups = [];

        foreach ($release->groups as $group) {
            $items = [];
            foreach ($group->items as $item) {
                $html = $this->markdown->toHtml($item);
                $items[] = ['html' => $html, 'text' => $this->markdown->htmlToText($html)];
            }

            $groups[] = [
                'summary_html' => $group->summary !== null ? $this->markdown->toHtml($group->summary) : null,
                'items' => $items,
            ];
        }

        return [
            'summary_html' => $release->summary !== null ? $this->markdown->toHtml($release->summary) : null,
            'groups' => $groups,
        ];
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

        return $prefix.':v'.self::CACHE_VERSION.':'.$locale.':'.sha1(implode("\n", $parts));
    }

    private function store(): CacheRepository
    {
        $store = $this->config->get('changelog.cache.store');

        return $this->cache->store(is_string($store) ? $store : null);
    }
}
