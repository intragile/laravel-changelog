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

Előbb olvasd el a hozzáférésről szóló részt. Az alapbeállítással (az `access` értéke
`authenticated`, az automatikus route-ok pedig csak az `api` csoportban vannak) a kérés csak akkor
megy át, ha az **alapértelmezett guard** már felismeri a felhasználót. Egy szokásos tokenes API-nál
(Sanctum) ez nem így van, ezért a végpontok a bejelentkezett felhasználónak is **401-et** adnak.

Ajánlott: kapcsold ki az automatikus route-okat, és a saját, már hitelesítő route-csoportodban
regisztráld őket. Előbb publikáld a configot:

```bash
php artisan vendor:publish --tag=changelog-config
```

Utána a publikált fájlban kapcsold ki az automatikus route-okat, és ha már ott vagy, nézd át a
`path` (a changelog-fájl, alapból `base_path('CHANGELOG.md')`) és a `locales` (minden nyelv, amelyet
az API kiszolgálhat, pl. `['en', 'hu']`) értékét:

```php
// config/changelog.php
'path' => base_path('CHANGELOG.md'),
'locales' => ['en', 'hu'],

'routes' => [
    'enabled' => false,
    'prefix' => 'changelog',
    'middleware' => ['api'],
    'name' => 'changelog.',
],
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

A publikált configban a teljes `routes` tömböt tartsd meg. A Laravel a csomag configját csak a
legfelső szinten fésüli össze, így az általad megadott `routes` tömb lecseréli a csomagét; a
kihagyott kulcsok az alábbi táblázat alapértékeit kapják.

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
hitelesítést nem ad, az alap route-csoport pedig csak `['api']`, ezért további beállítás nélkül sem
a Sanctum token, sem a munkamenet nem ismerhető fel, és 401 jön. Két megoldás:

1. **Ajánlott:** `routes.enabled = false`, és a `Changelog::routes()` hívása a saját hitelesített
   csoportodban, mint a gyors kezdésben; vagy
2. az automatikus route-ok megtartása és a hitelesítő middleware hozzáadása:

   ```php
   'routes' => [
       'enabled' => true,
       'prefix' => 'changelog',
       'middleware' => ['api', 'auth:sanctum'],
       'name' => 'changelog.',
   ],
   ```

Ha a Sanctum-guardod nem az alapértelmezett, biztonságosabb a gate vagy az egyedi csoport.
`public`-ot csak olyan adatra használj, amit nyilvános weboldalra is kitennél.

A körülvevő csoport middleware-jei (például az `auth:sanctum`) a csomag saját middleware-jei
**előtt** futnak, így az `Accept: application/json`-t kényszerítő előtt is. Az `Accept` fejléc
nélkül érkező vendéget ezért az alkalmazásod saját, hitelesítetlen kérésekre vonatkozó kezelése
utasítja el, amely alapból a bejelentkező route-ra irányít át. Állítsd be, hogy az alkalmazásod az
API-ra JSON-t adjon, például a `bootstrap/app.php`-ban:
`$exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'))`.

A gate vendégre is lefut, de a Laravel csak akkor hívja meg vendégnél a gate callbackjét, ha annak
első, user paramétere nullable, vagy `null` az alapértéke. Ha a gate-en vendéget is át akarsz engedni, így deklaráld:

```php
Gate::define('view-changelog', fn (?User $user): bool => $user === null || $user->can_see_releases);
```

Az a callback, amely kötelezően `User`-t vár, vagy egyáltalán nincs paramétere, minden vendéget 401-gyel utasít el.

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
- Az `url` a cím linkje (inline vagy `[1.2.0]: https://...` hivatkozás), vagy `null`. Csak `http`,
  `https` és relatív URL jut tovább; bármely más séma (`javascript:`, `data:`, ...) `null`-t ad.
- Az elem `html` mezője renderelt markdown; a `text` ugyanaz egyszerű szövegként.
- A legfelső `locale` a feloldott nyelv. A legfelső `fallback` akkor `true`, ha **a visszaadott
  oldal bármelyik kiadása** az alapnyelven jelenik meg, mert nincs hozzá fordítás.
- Minden kiadásnak saját `locale` (a szövege valójában melyik nyelvű) és `fallback` mezője van, így
  egy oldalon keveredhetnek a fordított és a visszaeső verziók.

### Nyelvfeloldás

Az első olyan találat nyer, amely szerepel a `locales`-ben; ami nincs benne, az a következő
lépésre esik át:

1. `?locale=hu`
2. `Accept-Language`, a kliens sorrendjében. Minden nyelv előbb a teljes címkéjével, aztán az
   elsődleges címkéjével illeszkedik: a `pt-BR` a beállított `pt-BR`-re (vagy `pt_BR`-re), ennek
   hiányában a `pt`-re illik; a `hu-HU` a `hu`-ra. A puszta `pt` nem illik a `pt-BR`-re.
3. az alkalmazás nyelve (`app()->getLocale()`)
4. `base_locale`

Ha az SPA a felhasználó nyelvét csak kliensoldalon tartja, küldjön `?locale=` vagy
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

A parse-hibákat (és figyelmeztetéseket) fájl- és sormegjelöléssel jelzi, ugyanígy az olyan
alapfájlt, amelyben nincs kiadás, az olyan fordítást, amelyből hiányzik egy kiadott verzió, vagy
amelyben az alapfájlban nem szereplő verzió van, valamint az alapfájltól eltérő dátumokat. Bármilyen
hibánál nem nulla kilépési kóddal tér vissza, így kiadás vagy pull request kapuja lehet:

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
Changelog::versions('hu');        // ugyanaz, mint a releases()
Changelog::find('1.2.0', 'hu');   // ?LocalizedRelease
Changelog::parsed();              // az alapfájl úgy, ahogy írva van, a parse-hibákkal
```

A `LocalizedRelease` tartalmazza a beolvasott `release`-t, a `locale`-t, ahonnan származik, és a
`fallback`-et.

## Nyilvános API és szemantikus verziózás

A csomag a [szemantikus verziózást](https://semver.org/lang/hu/) követi. Az 1.0.0-tól kezdve az
alábbiak bármelyikét érintő, visszafelé nem kompatibilis változás a következő főverzióig vár:

- a `Changelog` facade metódusai: `releases()`, `versions()`, `find()`, `latest()`, `parsed()`,
  `translationPath()` és `routes()`;
- a `LocalizedRelease`, `Release`, `ReleaseGroup`, `ParsedChangelog` és `ParseIssue` olvasható
  tulajdonságai (és a `ParseIssue::ERROR` / `ParseIssue::WARNING` konstansok);
- a config kulcsai és jelentésük;
- a route-ok, a nevük és a lekérdezési paramétereik, valamint a fent leírt JSON-szerkezetek;
- a `changelog:check` parancs, a kapcsolói és a kilépési kódja.

Nem tartozik ide: az értékobjektumok saját kézzel való létrehozása (a konstruktoruk új
paramétereket kaphat), valamint minden `@internal` jelölésű osztály és metódus: a controller, a
middleware-ek és a resource, a parancs osztálya, a nyelvfeloldó, a markdown-renderelő, a parser
és a repository konstruktora. A repositoryt a konténerből kérd el, vagy használd a facade-ot.

## Cache

A beolvasott kiadások a renderelt HTML-lel és szöveggel együtt nyelvenként, a beállított store-ban
vannak gyorsítótárazva, az alap- és a fordításfájlok útvonalából, módosítási idejéből és méretéből
képzett kulccsal. Ha egy deploy módosítja a fájlt, a változás anélkül jelenik meg, hogy bármit
törölni kellene. A bejegyzések csak tömböket és szövegeket tartalmaznak, így az objektumok
visszaállítását tiltó store-okkal (`cache.serializable_classes = false`) is működnek, és egy nap
után lejárnak, hogy a régi deployok kulcsai ne halmozódjanak fel.

Korlát: az előző szerkesztéssel azonos másodpercben végzett módosítás, ha a fájl mérete nem
változik, nem észlelhető. A fájl következő módosításakor jelenik meg, vagy ha törlöd a
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
