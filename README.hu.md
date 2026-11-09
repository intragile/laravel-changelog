# Laravel Changelog

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-changelog/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-changelog/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-changelog.svg)](https://packagist.org/packages/intragile/laravel-changelog)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-changelog.svg)](https://packagist.org/packages/intragile/laravel-changelog)
[![License](https://img.shields.io/packagist/l/intragile/laravel-changelog.svg)](LICENSE)

Az alkalmazás **Keep a Changelog formátumú `CHANGELOG.md`-jét** nyelvenkénti fordításokkal,
**beállítható hozzáférésű JSON API-n** adja ki, és egy Artisan paranccsal ellenőrzi a fájlt.

[English README → README.md](README.md)

- **Headless.** A csomag adatot ad, a „Mi újság” oldalt az SPA-d vagy a Blade nézeted rajzolja.
- **Egy igazságforrás.** A már vezetett changelog a tartalom. Nincs adatbázis, nincs második
  példány, amit karban kellene tartani, és nem olvas git-et.
- **Fordítás külön rendszer nélkül.** A `CHANGELOG.hu.md` a `CHANGELOG.md` mellett áll. Ha egy
  verzió hiányzik a fordításból, az alapfájl adott verziója jön, és ezt jelzi.
- **Tetszőleges csoportnév.** `### Added` és társai, de akár `### Admin interface` is, plusz
  szabad összefoglaló a verzió alatt.
- **Biztonságos HTML.** Minden elem nyers HTML nélkül, veszélyes linkek nélkül készül el, és
  egyszerű szövegként is megkapod.
- **Te döntöd el, ki éri el.** Nyilvános, csak bejelentkezettek, gate, vagy kikapcsolva.
- **A hibás fájl nem viszi el az oldalt.** A parser nem dob kivételt; a hibákat a
  `changelog:check` jelzi CI-ban.

## Telepítés

```bash
composer require intragile/laravel-changelog
```

PHP 8.3+ és Laravel 12 vagy 13 kell hozzá. A service provider automatikusan betöltődik. A configot
csak akkor publikáld, ha módosítani akarod:

```bash
php artisan vendor:publish --tag=changelog-config
```

## Gyors kezdés

Előbb olvasd el a hozzáférésről szóló részt: az alapbeállítással a végpontok **mindenkinek 401-et
adnak**, a bejelentkezett token-os felhasználónak is.

Ajánlott: kapcsold ki az automatikus route-okat, és a saját, már hitelesítő route-csoportodban
regisztráld őket.

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

A `Changelog::routes()` a configból maga teszi hozzá a saját előtagját (`changelog`) és a route-név
előtagot (`changelog.`), így a fenti csoport csak a külső előtagot és a hitelesítést adja.

## Beállítások

| Kulcs | Alap | Jelentés |
|---|---|---|
| `path` | `base_path('CHANGELOG.md')` | Az alap changelog-fájl. |
| `translation_pattern` | `CHANGELOG.{locale}.md` | A fordításfájl neve, a `path` könyvtárában. |
| `base_locale` | `en` | Az alapfájl nyelve. |
| `locales` | `['en']` | Az API által elfogadott nyelvek. Sorold fel az alapnyelvet és minden fordítást, pl. `['en', 'hu']`. |
| `access` | `authenticated` | `public`, `authenticated`, gate neve vagy `disabled`. Lásd lent. |
| `routes.enabled` | `true` | A route-ok automatikus regisztrálása. Kapcsold ki, ha a `Changelog::routes()`-t magad hívod. |
| `routes.prefix` | `changelog` | URL-előtag. |
| `routes.middleware` | `['api']` | Az automatikus route-csoport middleware-je. |
| `routes.name` | `changelog.` | Route-név előtag (`changelog.index`, `changelog.latest`, `changelog.show`). |
| `show_unreleased` | `false` | Az `[Unreleased]` szakasz szerepeljen-e a listában és a programozott elérésben. |
| `per_page` | `20` | Alap oldalméret, kiadásban mérve. |
| `max_per_page` | `100` | A `?per_page` felső határa. |
| `cache.store` | `null` | Cache store; `null` az alapértelmezettet használja. |
| `cache.prefix` | `changelog` | A cache-kulcsok előtagja. |

## Hozzáférés

Az `access` dönti el, ki hívhatja az API-t. Az ellenőrzés a csomag minden route-jának részét képező
middleware-ben fut, tehát az automatikus route-okra és a `Changelog::routes()`-ra is vonatkozik.

| Érték | Viselkedés |
|---|---|
| `public` | Bárki. |
| `authenticated` | Be kell jelentkezni, különben **401**. |
| gate neve | A `Gate::allows(név)` dönt. Az elutasított vendég **401**-et, az elutasított bejelentkezett felhasználó **403**-at kap. |
| `disabled` | Minden route **404**. |

Az `authenticated` a `$request->user()`-t nézi az **alapértelmezett guardon**. A csomag saját
hitelesítést nem ad, az alap route-csoport pedig csak `['api']`, ezért kapásból sem a Sanctum token,
sem a munkamenet nem ismerhető fel, és 401 jön. Két megoldás:

1. **Ajánlott:** `routes.enabled = false`, és a `Changelog::routes()` hívása a saját hitelesített
   csoportodban, mint a gyors kezdésben; vagy
2. az automatikus route-ok megtartása és a hitelesítő middleware hozzáadása:
   `'routes' => ['middleware' => ['api', 'auth:sanctum']]`.

Ha a Sanctum-guardod nem az alapértelmezett, biztonságosabb a gate vagy az egyedi csoport.
`public`-ot csak olyan adatra használj, amit nyilvános weboldalra is kitennél.

## API

Minden válasz JSON. A csomag a route-jain `Accept: application/json`-t kényszerít, és hagyományos
kivételeket dob (`AuthenticationException`, `ValidationException`, `NotFoundHttpException`), így a
hibákat **az alkalmazásod kivételkezelője formázza**, például `application/problem+json`-ra.

| Végpont | Leírás |
|---|---|
| `GET {prefix}` | A kiadások, legújabb elöl (fájlsorrend), lapozva. |
| `GET {prefix}/latest` | A legutóbbi kiadott verzió (soha nem az `Unreleased`). Ha nincs, 404. |
| `GET {prefix}/{version}` | Egy verzió, pl. `1.4.0` vagy `v1.4.0`. Ismeretlen: 404. |

A lista paraméterei: `page` (1 és 1000000 között) és `per_page` (1 és `max_per_page` között).
Érvénytelen érték 422 validációs hibát ad. Minden végpont elfogadja a `?locale=` paramétert.

```json
{
  "data": [
    {
      "version": "1.2.0",
      "date": "2026-11-09",
      "url": "https://github.com/acme/app/compare/v1.1.0...v1.2.0",
      "unreleased": false,
      "yanked": false,
      "summary_html": "<p>Rövid, szabad összefoglaló a verziócím alatt.</p>",
      "groups": [
        {
          "name": "Added",
          "summary_html": null,
          "items": [
            { "html": "<p>Export <strong>CSV</strong>-be.</p>", "text": "Export CSV-be." }
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

- A `latest` és a `{version}` egyetlen kiadás-objektumot ad, `data` alatt.
- Az `url` a cím linkhivatkozása (`[1.2.0]: https://...`), vagy `null`.
- Az elem `html` mezője renderelt markdown; a `text` ugyanaz egyszerű szövegként.
- A legfelső `locale` a feloldott nyelv. A legfelső `fallback` akkor `true`, ha **a visszaadott
  oldal bármelyik kiadása** az alapnyelven jelenik meg, mert nincs hozzá fordítás.
- Minden kiadásnak saját `locale` (a szövege valójában melyik nyelvű) és `fallback` mezője van, így
  egy oldalon keveredhetnek a fordított és a visszaeső verziók.

### Nyelvfeloldás

Az első olyan találat nyer, amely szerepel a `locales`-ben; ami nincs benne, az a következő
lépésre esik át:

1. `?locale=hu`
2. `Accept-Language` (az elsődleges címke, a kliens sorrendjében: a `hu-HU` a `hu`-ra illik)
3. az alkalmazás nyelve (`app()->getLocale()`)
4. `base_locale`

Az a SPA, amely a felhasználó nyelvét csak kliensoldalon tartja, küldjön `?locale=` vagy
`Accept-Language` értéket.

## Fordítások

A fordítást az alapfájl mellé tedd, a `translation_pattern` szerint elnevezve: az alapbeállítással a
`CHANGELOG.hu.md` a `CHANGELOG.md` mellé, és `'locales' => ['en', 'hu']`.

- A verziócímeknek és a **dátumoknak azonosnak kell maradniuk** az alapfájlban szereplőkkel; csak a
  szöveget fordítsd.
- A fordításból hiányzó verzió az alapfájlból jön, `fallback: true` jelzéssel és az alapnyelvre
  állított `locale`-lal. Ha a fordításfájl nincs meg, minden verzióra ez történik.
- A csoportneveket és az összefoglalókat szabadon fordíthatod; a fordítás úgy jelenik meg, ahogy
  írtad.

## A fájl ellenőrzése

```bash
php artisan changelog:check
php artisan changelog:check --locale=hu     # csak ez a fordítás; léteznie kell
```

Fájl és sor megjelöléssel jelzi a parse-hibákat (és figyelmeztetéseket), az olyan alapfájlt, amiben
nincs kiadás, a fordítást, amelyből hiányzik egy kiadott verzió, a fordítást, amelyben az alapfájlban
nem szereplő verzió van, és az alapfájltól eltérő dátumokat. Bármilyen hibánál nem nulla kilépési
kóddal tér vissza, így kiadás vagy pull request kapuja lehet:

```yaml
# .github/workflows/tests.yml
- name: Check the changelog
  run: php artisan changelog:check
```

Az `[Unreleased]`-et nem kell lefordítani. A nem létező fordításfájlt kihagyja, hacsak nem nevezed
meg a `--locale`-lal.

## Programozott elérés

```php
use IntrAgile\Changelog\Facades\Changelog;

Changelog::latest('hu');          // ?LocalizedRelease
Changelog::releases('hu');        // list<LocalizedRelease>, a show_unreleased szerint
Changelog::find('1.2.0', 'hu');   // ?LocalizedRelease
Changelog::parsed();              // az alapfájl úgy, ahogy írva van, a parse-hibákkal
```

A `LocalizedRelease` tartalmazza a beolvasott `release`-t, a `locale`-t, ahonnan származik, és a
`fallback`-et.

## Cache

A beolvasott kiadások nyelvenként, a beállított store-ban vannak gyorsítótárazva, az alap- és a
fordításfájlok útvonalából, módosítási idejéből és méretéből képzett kulccsal. A fájlt módosító
deploy tehát bármi törlése nélkül megjelenik.

Korlát: az a szerkesztés, amelyet az előzővel azonos másodpercben végzel, és a fájl mérete pontosan
ugyanaz marad, nem észlelhető. A fájl következő módosításakor jelenik meg, vagy ha törlöd a
cache-t. A gyakorlatban ez csak a fájl kézi, helyben szerkesztésekor számít; a deploy lecseréli a
fájlokat.

## Biztonság

A markdown nyers HTML **eltávolításával** készül (`html_input: strip`), a veszélyes linksémákat,
például a `javascript:`-et pedig kiveszi (`allow_unsafe_links: false`), így a `html` mező biztonsággal
beilleszthető egy oldalba. A nyelv a fájlnévbe kerülés előtt csak a beállított listából
származhat, ezért kéréssel nem lehet tetszőleges fájlt olvastatni a csomaggal. Lásd:
[SECURITY.md](SECURITY.md).

## Tesztelés és minőség

```bash
composer test
composer analyse
vendor/bin/pint --test
```

## Licenc

MIT. Lásd: [LICENSE](LICENSE).

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu).
