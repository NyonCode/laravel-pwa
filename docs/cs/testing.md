---
title: Testování
description: Jak testovat aplikaci s balíčkem (pomocníci a aserce) a jak vyvíjet a testovat balíček samotný (Pest, JS testy, Playwright, CI).
---

# Testování

## V aplikaci

### Pomocníci a aserce

Balíček přidává k `TestResponse` aserce (registruje je sám) a trait `InteractsWithPwa` pro
požadavky z nainstalované aplikace:

```php
// tests/TestCase.php
use NyonCode\LaravelPwa\Testing\InteractsWithPwa;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithPwa;
}
```

```php
it('allows the dashboard offline, but never the billing page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertPwaCacheable()->assertPwaUser($user);
    $this->actingAs($user)->get('/billing')->assertNotPwaCacheable();
});

it('remembers users of the installed app', function () {
    $user = User::factory()->create();

    $this->asInstalledApp()
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertCookie(Auth::guard()->getRecallerName());
});

it('tells the worker about the logout', function () {
    $this->actingAs(User::factory()->create())->post('/logout')->assertPwaLogout();
});

it('serves an installable manifest', function () {
    $this->get('/manifest.webmanifest')->assertValidManifest();
});
```

| Pomocník | Co ověří / udělá |
| --- | --- |
| `asInstalledApp($mode = 'standalone')` | pošle hlavičku `X-PWA-Display` a cookie `pwa_display` jako nainstalovaná aplikace |
| `asBrowserTab()` | totéž s režimem `browser` |
| `assertPwaCacheable()` | `X-PWA-Cache: allow` a žádné `Cache-Control: no-store` |
| `assertNotPwaCacheable()` | stránka do offline cache nesmí |
| `assertPwaLogout()` | `X-PWA-Logout: 1` a `Clear-Site-Data` bez `"storage"` |
| `assertPwaUser($user)` | `X-PWA-User` odpovídá uživateli (`null` = `guest`) |
| `assertValidManifest($strict = false)` | manifest je instalovatelný (`name`, `start_url`, `display`, ikony 192 a 512); se `strict` i bez varování (maskable ikona, `id`, délka `short_name`, barvy) |

Kontrolu manifestu najdete i samostatně: `app(ManifestValidator::class)->validate($manifest)`
vrátí `['errors' => [...], 'warnings' => [...]]`. Události balíčku testujte přes `Event::fake()`
([Události](events.md#testování)) a push notifikace přes `WebPush::fake()`
([Push notifikace](push.md#testování)).

### Doctor v CI

```yaml
- run: npm run build
- run: php artisan pwa:build
- run: php artisan pwa:doctor --ci
```

### V prohlížeči

Pest 4 browser testy nebo Playwright: `context.setOffline(true)`, navigace na stránku s `pwa.cache`,
očekávaný obsah z cache; navigace jinam, očekávaná offline obrazovka.

## Vývoj balíčku

```bash
composer install && npm install
composer check           # Pint, Rector, PHPStan (level max), Pest, MCP self-test
composer test:coverage   # Pest s pokrytím (pcov), selže pod 90 %
npm run test:js          # unit testy service workeru a klienta (node:test)
npm run lint:js          # ESLint
npm run test:e2e         # Playwright (Chromium + Firefox) proti workbenchi
```

Jeden test: `vendor/bin/pest --filter="název"` nebo `node --test tests/js/sw/runtime.test.mjs`.

### Workbench

`workbench/` je ukázková aplikace: `/` a `/orders` s `pwa.cache`, `/private` bez cache, formulář
na `/items`.

```bash
vendor/bin/testbench serve --port=8123
```

### Testy service workeru a klienta

`tests/js/sw/` spouští skutečný `resources/sw/runtime.js` v simulovaném prostředí service workeru
(`tests/js/support/sw-env.mjs`: Cache Storage v paměti, skriptovaný `fetch`, fake IndexedDB).
Ověřuje precache, souhlas `X-PWA-Cache`, oddělení uživatelů a mazání po odhlášení, 503 vs. offline,
limity a stáří obrázků, network-first API, frontu a její odeslání s obnovou CSRF.

`tests/js/client/` spouští `resources/js/pwa.js` v happy-dom (`tests/js/support/client-env.mjs`):
registrace, události, keep-alive a CSRF, opakování po 419 jen na vlastní origin, adaptéry, instalace.

### Prohlížečové testy

`tests/e2e/pwa.spec.mjs` (`@playwright/test`) si sám spustí workbench a ověří: registraci SW,
precache, cache jen povolených stránek, offline obrazovku, frontu a její odeslání, keep-alive,
údržbu (503, cache zůstane), `pwa:disable` a `pwa:enable`. Testy offline režimu (`@offline`) běží
jen v Chromiu, protože Playwright ve Firefoxu neomezí požadavky service workeru.

```bash
npx playwright install chromium firefox
npm run test:e2e
```

### CI

GitHub Actions: `tests.yml` (Pest na PHP 8.2–8.5 × Laravel 12/13 × lowest/stable a pokrytí),
`static.yml` (PHPStan, Pint, Rector, `composer audit`, MCP self-test), `js.yml` (ESLint a unit testy
na Node 20/22/24), `e2e.yml` (Playwright) a `release.yml` (tag `v*` vytvoří release z `CHANGELOG.md`).

### Struktura

```text
src/
  Pwa.php                         stav, clientConfig, cspHashes, nouzové vypnutí
  PwaServiceProvider.php          Packager konfigurace
  Facades/Pwa.php
  Manifest/ManifestBuilder.php    fluent API manifestu
  Manifest/DisplayStrategy.php    override | adaptive | fixed
  ServiceWorker/ServiceWorkerBuilder.php
  Http/Controllers/               AssetController, SessionController
  Http/Middleware/                PwaWebMiddleware, RememberStandalone, CachePage
  Listeners/MarkLogout.php
  Console/                        pwa:* příkazy
resources/
  js/pwa.js                       klient (window.Pwa)
  sw/runtime.js, kill-switch.js   service worker
  views/                          offline, maintenance, components/*
ai/                               AGENTS.md, skill, MCP server
docs/                             tato dokumentace (pořadí v nav.json)
```

### Pravidlo pro dokumentaci

Změna veřejného API, configu, příkazu nebo události = změna v `docs/` **a** v `ai/AGENTS.md` ve stejném
commitu. MCP server bere signatury z `src/` a config z `config/pwa.php` sám, ale prózu ne.
