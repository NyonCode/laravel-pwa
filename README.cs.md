# Laravel PWA

[![Latest version](https://img.shields.io/packagist/v/nyoncode/laravel-pwa.svg?label=packagist)](https://packagist.org/packages/nyoncode/laravel-pwa)
[![Tests](https://github.com/NyonCode/laravel-pwa/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/NyonCode/laravel-pwa/actions/workflows/tests.yml)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)](composer.json)
[![Laravel 12 | 13](https://img.shields.io/badge/Laravel-12%20%7C%2013-ff2d20.svg)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**Progressive Web App pro Laravel připravená do produkce.** Aplikace jde nainstalovat, funguje bez
připojení a nikdy neukáže jednomu uživateli data jiného, nainstalovaná zůstane přihlášená, má
skutečnou obrazovku údržby a service worker jde jedním příkazem vypnout na všech zařízeních.

[Dokumentace](https://nyoncode.github.io/laravel-pwa/cs/) · [Živé demo](https://nyoncode.github.io/laravel-pwa/demo/) · [English](README.md) · [Changelog](CHANGELOG.md) · [Upgrade](UPGRADE.md)

| Toast aktualizace | Offline: uložená stránka | Offline: neotevřená stránka | Instalace na iPhonu |
| --- | --- | --- | --- |
| ![Je k dispozici nová verze, s tlačítky Později a Aktualizovat](https://raw.githubusercontent.com/NyonCode/laravel-pwa/main/.github/media/update-toast.png) | ![Dříve otevřená stránka z cache s lištou offline](https://raw.githubusercontent.com/NyonCode/laravel-pwa/main/.github/media/offline-cached.png) | ![Offline obrazovka s tlačítkem Zkusit znovu](https://raw.githubusercontent.com/NyonCode/laravel-pwa/main/.github/media/offline.png) | ![Sdílet a pak Přidat na plochu](https://raw.githubusercontent.com/NyonCode/laravel-pwa/main/.github/media/ios-install.png) |

Snímky z [živého dema](https://nyoncode.github.io/laravel-pwa/demo/) (anglicky), statické kopie aplikace s nainstalovaným balíčkem.

## Rychlý start

```bash
composer require nyoncode/laravel-pwa
php artisan pwa:install
php artisan pwa:icons resources/pwa/icon.png
```

```blade
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <x-pwa::head/>
</head>
```

```bash
npm run build && php artisan pwa:build   # při každém nasazení (spouští ho i php artisan optimize)
php artisan pwa:doctor                   # kontrola HTTPS, ikon, buildu, výjimek z údržby, session
```

Tím máte instalovatelnou PWA s offline obrazovkou. Všechno ostatní se zapíná volitelně.

## Proč tento balíček

- **O cache rozhoduje server.** Žádná HTML stránka se neuloží, dokud to routa nepovolí middlewarem
  `pwa.cache`.
- **Cache patří uživateli.** Stránky, API odpovědi i obrázky se ukládají zvlášť pro každého
  přihlášeného uživatele a mažou se při odhlášení nebo přihlášení jiného uživatele, i když se
  přihlašuje přes XHR (Inertia, Livewire, SPA).
- **Údržba není výpadek sítě.** Worker odliší `503` od výpadku, ukáže obrazovku údržby a cache
  nechá být. `php artisan pwa:down` obaluje `artisan down`.
- **Nainstalovaná aplikace zůstane přihlášená.** Remember-me, klouzavá session a automatická
  obnova CSRF, takže žádné `419`.
- **Nouzové vypnutí.** `php artisan pwa:disable` odstraní service worker ze všech zařízení bez
  nasazení.
- **Jedna aplikace na jedné adrese.** Edge a Chrome na desktopu dostanou `minimal-ui` s tlačítky
  Zpět a Obnovit, telefony, tablety a Safari `standalone`. Bez detekce zařízení.
- **Push, který počítá se sdíleným zařízením.** Volitelný web push s kanálem pro Laravel notifikace:
  odhlášení odebere odběr zařízení a zařízení s jiným přihlášeným uživatelem ukáže místo obsahu
  neutrální notifikaci.

## Funkce

| Oblast | Co dostanete | Dokumentace |
| --- | --- | --- |
| Manifest | `/manifest.webmanifest` z configu a fluent API, režimy zobrazení, ikony, zkratky | [Manifest](docs/cs/manifest.md), [Režim zobrazení](docs/cs/display-modes.md) |
| Ikony a úvodní obrazovky | `pwa:icons`: ikony any, maskable, monochrome a pro iOS, úvodní obrazovky pro každý iPhone a iPad | [Příkazy](docs/cs/commands.md#pwaicons) |
| Dynamický manifest | Resolvery per request pro tenanty, branding a jazyky, bezpečné pro Octane | [Manifest](docs/cs/manifest.md) |
| Service worker | Sestavený z Vite manifestu, verzovaný, sám se aktualizuje | [Service worker](docs/cs/service-worker.md) |
| Pravidla cache | network-first, cache-first, stale-while-revalidate, network-only, cache-only; per uživatel nebo sdílené; limity a expirace | [Service worker](docs/cs/service-worker.md) |
| Offline | Offline obrazovka, stránky offline se souhlasem serveru, předehřátí nenavštívených stránek, fronta formulářů a API volání | [Offline](docs/cs/offline.md) |
| Push notifikace | Volitelné: `WebPushChannel`, `Pwa.push.subscribe()`, klíče VAPID, odběry po zařízeních, Declarative Web Push, `WebPush::fake()` | [Push notifikace](docs/cs/push.md) |
| Více tenantů | Samostatně instalovatelná aplikace pro každého tenanta na cestě (`/t/acme/`): vlastní scope workeru, identita v manifestu a cache | [Více tenantů](docs/cs/tenancy.md) |
| Session | Trvalé přihlášení v aplikaci, keep-alive, obnova CSRF | [Trvalé přihlášení](docs/cs/session.md) |
| Údržba | Obrazovka údržby, `pwa:down`, 503 vs. výpadek sítě | [Údržba](docs/cs/maintenance.md) |
| Nouzové vypnutí | `pwa:disable` / `pwa:enable` | [Nouzové vypnutí](docs/cs/kill-switch.md) |
| Klient | `window.Pwa`, události `pwa:*`, adaptéry Livewire a Inertia | [Klient](docs/cs/client.md), [Adaptéry](docs/cs/adapters.md) |
| Události | `ManifestResolving`, `ServiceWorkerBuilt`, nouzové vypnutí, telemetrie, push | [Události](docs/cs/events.md) |
| Testování | `asInstalledApp()`, `assertPwaCacheable()`, `assertValidManifest()` a další | [Testování](docs/cs/testing.md) |
| Rozšiřitelnost | `sw.import` / `sw.extend` a `self.PwaWorker` pro vlastní kód workeru | [Service worker](docs/cs/service-worker.md) |
| AI agenti | MCP server, průvodce pro agenty a Claude Code skill z nainstalované verze | [AI agenti](docs/cs/ai.md) |

## Ukázka API

```php
// Stránky dostupné offline (server dává souhlas per routa)
Route::get('/dashboard', DashboardController::class)->middleware('pwa.cache');

// Remember-me v nainstalované aplikaci
Route::post('/login', LoginController::class)->middleware('pwa.remember');

// Pravidla cache pro API, v AppServiceProvider::boot()
Pwa::serviceWorker()->cache('/api/catalog*')->staleWhileRevalidate()->maxEntries(200)->maxAge(days: 1);

// Manifest pro každého tenanta
Pwa::manifest()->resolveUsing(fn (Request $request, ManifestBuilder $manifest) => $manifest
    ->name($request->tenant()->name)
    ->themeColor($request->tenant()->color));
```

```php
// V testech
$this->asInstalledApp()->get('/dashboard')->assertPwaCacheable();
$this->get('/manifest.webmanifest')->assertValidManifest();
```

## Příkazy

| Příkaz | Co dělá |
| --- | --- |
| `pwa:install` | Publikuje config a vypíše další kroky |
| `pwa:build` / `pwa:clear` | Sestaví / smaže service worker (napojeno na `optimize`) |
| `pwa:icons {source}` | Ikony any, maskable, apple-touch-icon a favicon |
| `pwa:down` | `artisan down` s obrazovkou údržby PWA |
| `pwa:disable` / `pwa:enable` | Nouzové vypnutí service workeru na všech zařízeních |
| `pwa:push:vapid` | Vygeneruje klíče VAPID pro web push |
| `pwa:doctor` | Kontrola HTTPS, ikon, buildu, pravidel cache, výjimek z údržby, session |

## Dokumentace

| Začínáme | Funkce | Integrace | Provoz |
| --- | --- | --- | --- |
| [Přehled](docs/cs/index.md) | [Režim zobrazení](docs/cs/display-modes.md) | [JavaScript klient](docs/cs/client.md) | [Příkazy](docs/cs/commands.md) |
| [Instalace](docs/cs/installation.md) | [Manifest](docs/cs/manifest.md) | [Události](docs/cs/events.md) | [Nasazení](docs/cs/deployment.md) |
| [Konfigurace](docs/cs/configuration.md) | [Blade komponenty](docs/cs/components.md) | [Více tenantů](docs/cs/tenancy.md) | [Bezpečnost](docs/cs/security.md) |
| | [Service worker](docs/cs/service-worker.md) | [Livewire a Inertia](docs/cs/adapters.md) | [Podpora platforem](docs/cs/platforms.md) |
| | [Offline](docs/cs/offline.md) | [Push notifikace](docs/cs/push.md) | [Řešení problémů](docs/cs/troubleshooting.md) |
| | [Trvalé přihlášení](docs/cs/session.md) | [AI agenti](docs/cs/ai.md) | [Testování](docs/cs/testing.md) |
| | [Údržba](docs/cs/maintenance.md) | | [Verzování](docs/cs/versioning.md) |
| | [Nouzové vypnutí](docs/cs/kill-switch.md) | [Recepty: startovací sady, Fortify, Filament](docs/cs/recipes.md) | |

## Podpora verzí

| Verze | PHP | Laravel | Stav |
| --- | --- | --- | --- |
| 1.x | 8.2 – 8.5 | 12, 13 | Aktivní |

Balíček se řídí [sémantickým verzováním](docs/cs/versioning.md): minor verze nikdy nerozbijí veřejné
API. Laravel 11 a starší jsou po konci podpory a balíček je nepodporuje. Každé vydání se testuje
s nejnižšími i nejnovějšími závislostmi na všech podporovaných verzích PHP a Laravelu.

## AI agenti a MCP

```bash
vendor/bin/pwa-ai install
```

Přidá blok do `AGENTS.md`, Claude Code skill `laravel-pwa` a MCP server `laravel-pwa` do
`.mcp.json` (Node 18+, bez závislostí). Agent tak čte dokumentaci verze z vašeho `composer.lock`.
Viz [AI agenti](docs/cs/ai.md).

## Přispívání a bezpečnost

Viz [CONTRIBUTING.md](CONTRIBUTING.md). Zranitelnosti hlaste neveřejně podle
[SECURITY.cs.md](SECURITY.cs.md), ne ve veřejných issues.

## Licence

MIT, viz [LICENSE.md](LICENSE.md).
