---
title: Konfigurace
description: Kompletní reference config/pwa.php – každý klíč, typ, výchozí hodnota a dopad.
---

# Konfigurace

Všechny volby jsou v `config/pwa.php` (publikuje `php artisan pwa:install` nebo
`php artisan vendor:publish --tag="pwa::config"`). Změny configu mění verzi service workeru,
takže po nasazení s `pwa:build` se zařízení aktualizují sama.

## Proměnné prostředí

| Proměnná | Klíč | Výchozí |
| --- | --- | --- |
| `PWA_ENABLED` | `enabled` | `true` mimo `APP_ENV=local` |
| `PWA_NAME` | `name` | `APP_NAME` |
| `PWA_SHORT_NAME` | `short_name` | `null` (= `name`) |
| `PWA_DESCRIPTION` | `description` | `null` |
| `PWA_DISPLAY_STRATEGY` | `display.strategy` | `override` |
| `PWA_KILL_SWITCH_STORE` | `sw.kill_switch_store` | `null` (výchozí cache store) |
| `PWA_LOG_CHANNEL` | `telemetry.channel` | `null` (výchozí log) |

## Obecné

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `enabled` | bool | mimo local `true` | Zapne service worker. Při `false` klient existující SW odregistruje a `/sw.js` vrací samodestrukční SW. Manifest a komponenty fungují dál. |
| `route_prefix` | string | `pwa` | Prefix interních rout (`/pwa/session`, `/pwa/ping`, `/pwa/client.js`, `/pwa/telemetry`). |

## Identita

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `name` | string | `APP_NAME` | Plný název v instalačním dialogu a seznamu aplikací. |
| `short_name` | ?string | `null` | Pod ikonou na ploše; do 12 znaků. Bez něj se použije `name`. |
| `description` | ?string | `null` | Popis v instalačním dialogu (Chromium). |
| `id` | string | `/` | Identita aplikace. **Po vydání neměnit**: změna = nová aplikace, stará zůstane nainstalovaná. |
| `start_url` | string | `/?source=pwa` | Kam se aplikace otevře. `source=pwa` označí session jako nainstalovanou aplikaci (ne pro remember-me). |
| `scope` | string | `/` | Které URL patří aplikaci. `start_url` musí ležet uvnitř. |

Texty `name`, `short_name` a `description` jdou přeložit v
`lang/vendor/pwa/{locale}/manifest.php` (klíče `name`, `short_name`, `description`).

## Zobrazení

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `display.strategy` | `override`, `adaptive`, `fixed` | `override` | Viz [Režim zobrazení](display-modes.md). |
| `display.value` | string | `standalone` | Hodnota `display` (`standalone`, `minimal-ui`, `fullscreen`, `browser`). |
| `display.override` | list | `['minimal-ui']` | `display_override` pro strategii `override`. |
| `display.desktop` | string | `minimal-ui` | Režim pro desktop u strategie `adaptive`. |
| `orientation` | ?string | `null` | Např. `portrait`; většinou nenastavovat (tablety). |
| `desktop_nav.enabled` | bool | `true` | Vykreslovat `<x-pwa::nav/>`. |
| `desktop_nav.min_width` | int | `900` | Od jaké šířky okna se komponenta považuje za desktop. |

## Vzhled a ikony

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `theme.light` | hex | `#0d6efd` | `theme_color` a světlá `theme-color`; akcent offline a údržby. |
| `theme.dark` | hex | `#0b1220` | `theme-color` pro tmavý režim. |
| `theme.accent_dark` | hex | `#5b9dff` | Akcent obrazovek offline a údržby v tmavém režimu. |
| `theme.background` | hex | `#ffffff` | `background_color` (splash na Androidu), pozadí maskable a iOS ikony. |
| `icons.source` | ?string | `null` | Výchozí zdroj pro `pwa:icons`. |
| `icons.path` | string | `pwa/icons` | Složka ikon v `public/`. |
| `icons.sizes` | `list<int>` | `[192, 512]` | Velikosti `icon-{size}.png`. |
| `icons.maskable_padding` | float | `0.1` | Okraj maskable ikony (bezpečná zóna je 80 %). |
| `icons.splash.background` | ?hex | `null` | Pozadí úvodních obrazovek iOS; `null` = `theme.background`. |
| `icons.splash.dark_background` | ?hex | `null` | Přidá varianty pro `prefers-color-scheme: dark` s tímto pozadím. |
| `icons.splash.icon_size` | float | `0.3` | Hrana ikony jako podíl kratší hrany obrazovky. |
| `icons.splash.orientations` | `list<string>` | `['portrait', 'landscape']` | Orientace, které se vytvoří. |
| `icons.splash.devices` | `?list<string>` | `null` | Klíče `AppleDevices::SCREENS`; `null` = všechny iPhony a iPady. |
| `screenshots` | list | `[]` | Screenshoty pro bohatší instalační dialog (`src`, `sizes`, `type`, `form_factor`), viz [Manifest](manifest.md#screenshoty). |

## Integrace do systému

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `categories` | list | `[]` | Kategorie obchodů a launcherů, např. `['business']`. |
| `shortcuts` | list | `[]` | Zkratky na ikoně: `['name' => …, 'url' => …, 'icon' => …]`. |
| `share_target` | ?array | `null` | Celý objekt `share_target`; pohodlněji přes `Pwa::manifest()->shareTarget()`. |
| `file_handlers` | list | `[]` | Otevírání souborů z OS (Chromium desktop). |
| `protocol_handlers` | list | `[]` | Vlastní schémata `web+…`. |
| `launch_handler` | array | `client_mode: [navigate-existing, auto]` | Jak se otevře aplikace, která už běží. |
| `scope_extensions` | list | `[]` | Další domény jedné aplikace; vyžaduje `/.well-known/web-app-origin-association` na nich. |
| `locales` | list | `[]` | Jazyky manifestu včetně výchozího: `*_localized` členy (Chromium 147+) a odkaz `?lang=` podle jazyka stránky. |

## Service worker

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `sw.url` | string | `/sw.js` | URL service workeru. Nikdy nedávejte soubor se stejným názvem do `public/`. |
| `sw.import` | list | `[]` | Skripty načtené přes `importScripts` **před** runtime (push systém, Sentry). Jejich obsah mění verzi SW. |
| `sw.extend` | list | `[]` | Skripty načtené **po** runtime; mají `self.PwaWorker` (vlastní trasy a strategie), viz [Service worker](service-worker.md#swextend). |
| `sw.vite_manifest` | string | `build/manifest.json` | Vite manifest v `public/` pro precache. |
| `sw.build_directory` | string | `build` | Prefix assetů Vite (cache-first). |
| `sw.compiled_path` | string | `bootstrap/cache/pwa` | Kam `pwa:build` uloží sestavený SW. |
| `sw.kill_switch_store` | ?string | `null` | Cache store pro `pwa:disable`; při více serverech sdílený (Redis, databáze). |

## Cache

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `manifest.resolvers` | list | `[]` | Třídy `ManifestResolver` (z kontejneru) pro manifest podle požadavku, viz [Manifest](manifest.md#resolvery-tenanti-a-branding). |
| `cache.navigation_timeout` | int (s) | `3` | Po kolika sekundách pomalé sítě použít stránku z cache. |
| `cache.rules` | list | `[]` | Pravidla cache (`match`, `destination`, `strategy`, `cache`, `max_entries`, `max_age`, `timeout`, `shared`), viz [Pravidla cache](service-worker.md#pravidla-cache). |
| `cache.api` | list | `[]` | Zkratka: GET cesty (s `*`) s network-first, např. `['/api/catalog*']`. |
| `cache.images.enabled` | bool | `true` | Výchozí pravidlo pro obrázky a fonty (stale-while-revalidate). |
| `cache.images.max_entries` | int | `120` | Limit obrázků a fontů v cache. |
| `cache.images.max_age` | int (s) | 30 dní | Starší obrázek se načte znovu (offline se použije i starší). |
| `cache.never_cache` | list | login, logout, register, broadcasting, livewire, sanctum, `/pwa/*` | Cesty, které SW nikdy neuloží ani nezařadí do fronty; fetch/XHR jen propustí na síť a přečte z nich identitu uživatele. |

## Offline

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `offline.enabled` | bool | `true` | Offline obrazovka a její routa. |
| `offline.route` | string | `/offline` | URL offline obrazovky (precache). |
| `offline.precache` | list | `[]` | Další URL do precache, např. `['/manifest.webmanifest', '/img/logo.svg']`. |
| `offline.queue` | bool | `true` | Ukládat POST/PUT/PATCH/DELETE při výpadku sítě do fronty. |

## Session

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `session.standalone_remember` | bool | `true` | `pwa.remember` vynutí remember-me v nainstalované aplikaci. |
| `session.keepalive_minutes` | int | `10` | Interval keep-alive, když je aplikace vidět (`0` = jen při návratu). |
| `session.refresh_csrf` | bool | `true` | Obnovovat CSRF token a opakovat `fetch` po 419. |
| `session.remember_field` | string | `remember` | Název pole remember-me ve formuláři přihlášení. |

## Údržba

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `maintenance.message` | ?string | `null` | Zpráva obrazovky údržby (jinak přeložený výchozí text). |
| `maintenance.until` | ?string | `null` | Očekávaný konec (ISO 8601); nastavuje `pwa:down --until`. |
| `maintenance.retry` | int (s) | `60` | Výchozí `Retry-After` a interval kontrol (15–300 s). |
| `maintenance.refresh` | ?int | `null` | Výchozí `--refresh` pro `pwa:down`. |

## Instalace, telemetrie, adaptéry

| Klíč | Typ | Výchozí | Popis |
| --- | --- | --- | --- |
| `install.min_visits` | int | `2` | Od kolikáté návštěvy ukazovat `<x-pwa::install/>`. |
| `install.dismiss_days` | int | `30` | Jak dlouho po odmítnutí výzvu neukazovat. |
| `install.ios_hint` | bool | `true` | Nápověda „Sdílet → Přidat na plochu“ na iPhonu a iPadu. |
| `telemetry.log` | bool | `true` | Zapisovat `TelemetryReceived` do logu; vypněte, když události zpracováváte sami ([Události](events.md)). |
| `telemetry.enabled` | bool | `true` | Posílat chyby service workeru na `/pwa/telemetry`. |
| `telemetry.channel` | ?string | `null` | Log kanál; neexistující kanál = výchozí log. |
| `telemetry.max_events` | int | `20` | Max. událostí v jednom požadavku. |
| `adapters.livewire` | bool | `true` | Adaptér pro Livewire 3. |
| `adapters.inertia` | bool | `true` | Adaptér pro Inertia. |
| `adapters.reload_on_resume` | bool | `false` | Inertia `reload()` po návratu do aplikace. |
