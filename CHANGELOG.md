# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-11-10

### Fixed

- Every endpoint now validates the optional `?locale=` query parameter (a string of at most 20
  characters), so OpenAPI generators such as Scramble document it. A supported locale is served,
  and an empty or unsupported one falls through to `Accept-Language` as before; only an array or a
  value over 20 characters now answers 422 instead of being ignored.
- A cache entry that does not have the expected shape is rebuilt and overwritten instead of
  failing the request with a `TypeError`.
- A protocol-relative heading link (`//host`) gives `url: null`; it was passed on as if it were
  relative.
- The package no longer calls the global helpers that only `laravel/framework` defines (`abort`,
  `app`, `config`, `config_path`), and it declares `illuminate/container`, which it uses.
- README: item `text` has its HTML entities decoded and must be escaped before it goes into HTML,
  and the cache store must be trusted, because cached HTML is served without rendering it again.

## [1.0.0] - 2026-11-09

### Added

- Parser for Keep a Changelog files: `## [x.y.z] - YYYY-MM-DD` and `## [Unreleased]` releases,
  a free summary under each version, the standard groups and any custom group name, multi-line
  and nested list items, `[YANKED]` releases and link references.
- Safe rendering of every item and summary: inline markdown becomes HTML with raw HTML stripped
  and unsafe links removed, and is also returned as plain text.
- Translations in `CHANGELOG.{locale}.md` beside the base file, with a per-version fallback to the
  base file and `locale` / `fallback` markers on every release and on the list.
- JSON API: `GET {prefix}` (paginated), `GET {prefix}/latest` and `GET {prefix}/{version}`.
- Locale resolution from `?locale=`, then `Accept-Language` (full tag such as `pt-BR` first, then
  the primary tag), then the application locale, limited to the configured locales.
- Access control: `public`, `authenticated`, the name of a gate, or `disabled`.
- `Changelog::routes()` to register the routes inside the host application's own route group.
- Programmatic access through the `Changelog` facade: `releases()` (alias `versions()`), `find()`,
  `latest()`, `parsed()` and `translationPath()`.
- Caching keyed on the path, modification time and size of the files, so a deploy never serves
  stale content.
- `changelog:check` command: parse errors, translations missing a version or carrying an extra
  one, and dates that differ from the base file. Exits non-zero on any error, for CI.

[1.0.1]: https://github.com/intragile/laravel-changelog/releases/tag/v1.0.1
[1.0.0]: https://github.com/intragile/laravel-changelog/releases/tag/v1.0.0

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
