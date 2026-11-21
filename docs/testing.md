---
title: Testování
description: Jak testovat aplikaci s balíčkem a jak vyvíjet a testovat balíček samotný (Pest, workbench, prohlížečový test).
---

# Testování

## V aplikaci

### Feature testy

```php
it('allows the dashboard offline', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertHeader('X-PWA-Cache', 'allow');
});

it('remembers users of the installed app', function () {
    $user = User::factory()->create();

    $this->withHeaders(['X-PWA-Display' => 'standalone'])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertCookie(Auth::guard()->getRecallerName());
});

it('serves a valid manifest', function () {
    $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('display_override.0', 'minimal-ui');
});
```

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
composer install
composer test          # Pest
composer analyse       # PHPStan / Larastan (úroveň 6)
composer format        # Pint
node --check resources/js/pwa.js
node --check resources/sw/runtime.js
node ai/mcp/server.mjs --self-test
```

### Workbench

`workbench/` je ukázková aplikace: `/` a `/orders` s `pwa.cache`, `/private` bez cache, formulář
na `/items`.

```bash
vendor/bin/testbench serve --port=8123
```

### Prohlížečový test

`tests/Browser/e2e.py` (Playwright, Python) proti běžícímu workbenchi ověří: registraci SW,
precache, cache jen povolených stránek, offline obrazovku, frontu a její odeslání, keep-alive,
údržbu (503, cache zůstane), `pwa:disable` a `pwa:enable`.

```bash
pip install playwright && playwright install chromium
vendor/bin/testbench serve --port=8123 &
PHP=php python3 tests/Browser/e2e.py
```

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
