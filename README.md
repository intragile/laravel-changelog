# Laravel Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-changelog/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-changelog/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-changelog.svg)](https://packagist.org/packages/intragile/laravel-changelog)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-changelog.svg)](https://packagist.org/packages/intragile/laravel-changelog)
[![License](https://img.shields.io/packagist/l/intragile/laravel-changelog.svg)](LICENSE)

Serves your application's **Keep a Changelog `CHANGELOG.md`**, with per-language translations,
over a **JSON API with configurable access**, and checks the file with an Artisan command.

[Magyar nyelvű leírás → README.hu.md](README.hu.md)

- **Headless.** The package returns data; your SPA or Blade view draws the "What's new" page.
- **One source of truth.** The changelog you already keep is the content. No database, no second
  copy to maintain, no git access.
- **Translations without a second system.** `CHANGELOG.hu.md` sits next to `CHANGELOG.md`. A
  version missing from a translation falls back to the base file for that version and says so.
- **Any group name.** `### Added` and friends, but also `### Admin interface` or whatever your
  file uses, plus a free summary under each version.
- **Safe HTML.** Every item is rendered with raw HTML stripped and unsafe links removed, and is
  also returned as plain text.
- **Access you choose.** Public, signed-in users only, a gate, or off.
- **A bad file does not take the page down.** The parser never throws; `changelog:check` reports
  the problems in CI.

## Installation

```bash
composer require intragile/laravel-changelog
```

Requires PHP 8.3+ and Laravel 12 or 13. The service provider is discovered automatically.
Publish the config only if you need to change it:

```bash
php artisan vendor:publish --tag=changelog-config
```

## Quick start

Read the access section first: with the shipped defaults the endpoints answer **401 to everyone**,
including signed-in token users.

Recommended: turn the automatic routes off and register the routes inside the route group that
already authenticates your users.

```php
// config/changelog.php
'routes' => ['enabled' => false],
```

```php
// routes/api.php
use IntrAgile\Changelog\Facades\Changelog;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Changelog::routes();   // GET /api/v1/changelog, /changelog/latest, /changelog/{version}
});
```

`Changelog::routes()` adds its own `prefix` (`changelog`) and route-name prefix (`changelog.`) from
the config, so the group above only supplies the outer prefix and the authentication.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `path` | `base_path('CHANGELOG.md')` | The base changelog file. |
| `translation_pattern` | `CHANGELOG.{locale}.md` | Translation file name, resolved in the same directory as `path`. |
| `base_locale` | `en` | The language of the base file. |
| `locales` | `['en']` | The locales the API accepts. List the base locale and every translation, e.g. `['en', 'hu']`. |
| `access` | `authenticated` | `public`, `authenticated`, a gate name or `disabled`. See below. |
| `routes.enabled` | `true` | Register the routes automatically. Turn off to call `Changelog::routes()` yourself. |
| `routes.prefix` | `changelog` | URL prefix. |
| `routes.middleware` | `['api']` | Middleware of the automatic route group. |
| `routes.name` | `changelog.` | Route name prefix (`changelog.index`, `changelog.latest`, `changelog.show`). |
| `show_unreleased` | `false` | Include the `[Unreleased]` section in the list and the programmatic API. |
| `per_page` | `20` | Default page size, in releases. |
| `max_per_page` | `100` | Upper limit for `?per_page`. |
| `cache.store` | `null` | Cache store; `null` uses the default store. |
| `cache.prefix` | `changelog` | Cache key prefix. |

## Access

`access` decides who may call the API. The check runs in a middleware that is part of every
package route, so it applies both to the automatic routes and to `Changelog::routes()`.

| Value | Behaviour |
|---|---|
| `public` | Anyone. |
| `authenticated` | A user must be signed in; otherwise **401**. |
| a gate name | `Gate::allows(name)` decides. A denied guest gets **401**, a denied signed-in user **403**. |
| `disabled` | **404** for every route. |

`authenticated` checks `$request->user()` on the **default guard**. The package adds no
authentication of its own, and the default route group is only `['api']`, so out of the box a
Sanctum token or a session user is not recognised and gets 401. Either:

1. **Recommended:** `routes.enabled = false` and call `Changelog::routes()` inside your own
   authenticated group, as in the quick start; or
2. keep the automatic routes and add your authentication middleware:
   `'routes' => ['middleware' => ['api', 'auth:sanctum']]`.

If your Sanctum guard is not the default guard, a gate or a custom group is the safer choice.
Use `public` only for data you would also put on a public web page.

## API

All responses are JSON. The package forces `Accept: application/json` on its routes and throws
ordinary exceptions (`AuthenticationException`, `ValidationException`, `NotFoundHttpException`),
so **your application's exception handler formats the errors**, for example as
`application/problem+json`.

| Endpoint | Description |
|---|---|
| `GET {prefix}` | Releases, newest first (file order), paginated. |
| `GET {prefix}/latest` | The newest released version (never `Unreleased`). 404 if there is none. |
| `GET {prefix}/{version}` | One version, e.g. `1.4.0` or `v1.4.0`. 404 if unknown. |

Query parameters of the list: `page` (1 to 1000000) and `per_page` (1 to `max_per_page`). Invalid
values give a 422 validation error. Every endpoint also takes `?locale=`.

```json
{
  "data": [
    {
      "version": "1.2.0",
      "date": "2026-11-09",
      "url": "https://github.com/acme/app/compare/v1.1.0...v1.2.0",
      "unreleased": false,
      "yanked": false,
      "summary_html": "<p>A short free summary under the version heading.</p>",
      "groups": [
        {
          "name": "Added",
          "summary_html": null,
          "items": [
            { "html": "<p>Export to <strong>CSV</strong>.</p>", "text": "Export to CSV." }
          ]
        }
      ],
      "locale": "hu",
      "fallback": false
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 },
  "locale": "hu",
  "fallback": false
}
```

- `latest` and `{version}` return a single release object, wrapped in `data`.
- `url` is the link reference of the heading (`[1.2.0]: https://...`), or `null`.
- Item `html` is rendered markdown; `text` is the same content as plain text.
- Top level `locale` is the resolved language. Top level `fallback` is `true` when **any release
  on the returned page** is shown in the base language because it has no translation.
- Each release carries its own `locale` (the language its text actually comes from) and
  `fallback`, so a page can mix translated and fallback versions.

### Language resolution

The first of these that is a configured locale wins; anything not in `locales` falls through to
the next step:

1. `?locale=hu`
2. `Accept-Language` (the primary tag, in the client's order: `hu-HU` matches `hu`)
3. the application locale (`app()->getLocale()`)
4. `base_locale`

A single-page application that keeps the user's language client-side should send `?locale=` or
`Accept-Language`.

## Translations

Put a translation beside the base file, named by `translation_pattern`: with the defaults,
`CHANGELOG.hu.md` next to `CHANGELOG.md`, and `'locales' => ['en', 'hu']`.

- Version headings and **dates must stay identical** to the base file; translate only the text.
- A version missing from a translation is served from the base file for that version, with
  `fallback: true` and `locale` set to the base locale. A missing translation file does the same
  for every version.
- Group names and summaries are translated freely; the translation is shown as written.

## Checking the file

```bash
php artisan changelog:check
php artisan changelog:check --locale=hu     # only this translation; it must exist
```

It reports, with file and line, parse errors (and warnings), a base file with no releases, a
translation that lacks a released version, a translation that has a version the base does not,
and dates that differ from the base file. It exits with a non-zero status when there is any
error, so it can gate a release or a pull request:

```yaml
# .github/workflows/tests.yml
- name: Check the changelog
  run: php artisan changelog:check
```

`[Unreleased]` does not have to be translated. A translation file that does not exist is
skipped unless you name it with `--locale`.

## Programmatic use

```php
use IntrAgile\Changelog\Facades\Changelog;

Changelog::latest('hu');          // ?LocalizedRelease
Changelog::releases('hu');        // list<LocalizedRelease>, honours show_unreleased
Changelog::find('1.2.0', 'hu');   // ?LocalizedRelease
Changelog::parsed();              // the base file as written, with parse issues
```

A `LocalizedRelease` holds the parsed `release`, the `locale` it comes from and `fallback`.

## Caching

Parsed releases are cached per locale, in the configured store, under a key built from the path,
modification time and size of the base and translation files. A deploy that changes a file
therefore shows up without clearing anything.

Limitation: an edit made within the same second as the previous one that leaves the file size
exactly the same is not detected. It appears with the next change to the file, or after you clear
the cache. In practice this only matters when you edit a file in place by hand; deploys replace
files.

## Security

Markdown is rendered with raw HTML **stripped** (`html_input: strip`) and unsafe link schemes
such as `javascript:` removed (`allow_unsafe_links: false`), so `html` is safe to insert into a
page. Locales only ever come from the configured allow-list before they reach a file name, so a
request cannot make the package read an arbitrary file. See [SECURITY.md](SECURITY.md).

## Testing and quality

```bash
composer test
composer analyse
vendor/bin/pint --test
```

## License

MIT. See [LICENSE](LICENSE).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
