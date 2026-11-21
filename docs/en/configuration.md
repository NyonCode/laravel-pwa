---
title: Configuration
description: The complete config/pwa.php reference, with every key, its type, its default and its effect.
---

# Configuration

All options live in `config/pwa.php` (published by `php artisan pwa:install` or
`php artisan vendor:publish --tag="pwa::config"`). Config changes change the service worker version,
so devices update themselves after you deploy with `pwa:build`.

## Environment variables

| Variable | Key | Default |
| --- | --- | --- |
| `PWA_ENABLED` | `enabled` | `true` unless `APP_ENV=local` |
| `PWA_NAME` | `name` | `APP_NAME` |
| `PWA_SHORT_NAME` | `short_name` | `null` (= `name`) |
| `PWA_DESCRIPTION` | `description` | `null` |
| `PWA_DISPLAY_STRATEGY` | `display.strategy` | `override` |
| `PWA_KILL_SWITCH_STORE` | `sw.kill_switch_store` | `null` (default cache store) |
| `PWA_LOG_CHANNEL` | `telemetry.channel` | `null` (default log) |
| `PWA_PUSH_ENABLED` | `push.enabled` | `false` |
| `PWA_VAPID_SUBJECT` | `push.vapid.subject` | `APP_URL` |
| `PWA_VAPID_PUBLIC_KEY`, `PWA_VAPID_PRIVATE_KEY` | `push.vapid.*` | `null` (`pwa:push:vapid`) |

## General

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | bool | `true` outside local | Enables the service worker. When `false`, the client unregisters any existing service worker and `/sw.js` returns a self-destroying service worker. The manifest and components keep working. |
| `route_prefix` | string | `pwa` | Prefix for the internal routes (`/pwa/session`, `/pwa/ping`, `/pwa/client.js`, `/pwa/telemetry`). |

## Identity

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `name` | string | `APP_NAME` | Full name in the install dialog and the app list. |
| `short_name` | ?string | `null` | Shown under the home screen icon; 12 characters or fewer. Falls back to `name`. |
| `description` | ?string | `null` | Description in the install dialog (Chromium). |
| `id` | string | `/` | The app's identity. **Never change it after release**: a new `id` means a new app, and the old one stays installed. |
| `start_url` | string | `/?source=pwa` | Where the app opens. `source=pwa` marks the session as an installed app (not used for remember-me). |
| `scope` | string | `/` | Which URLs belong to the app. `start_url` must be inside it. |

You can translate `name`, `short_name` and `description` in
`lang/vendor/pwa/{locale}/manifest.php` (keys `name`, `short_name`, `description`).

## Display

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `display.strategy` | `override`, `adaptive`, `fixed` | `override` | See [Display mode](display-modes.md). |
| `display.value` | string | `standalone` | The `display` value (`standalone`, `minimal-ui`, `fullscreen`, `browser`). |
| `display.override` | list | `['minimal-ui']` | `display_override` for the `override` strategy. |
| `display.desktop` | string | `minimal-ui` | The desktop mode for the `adaptive` strategy. |
| `orientation` | ?string | `null` | For example `portrait`; usually best left unset (tablets). |
| `desktop_nav.enabled` | bool | `true` | Render `<x-pwa::nav/>`. |
| `desktop_nav.min_width` | int | `900` | The window width from which the component treats the device as a desktop. |

## Appearance and icons

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `theme.light` | hex | `#0d6efd` | `theme_color` and the light `theme-color`; accent of the offline and maintenance screens. |
| `theme.dark` | hex | `#0b1220` | `theme-color` for dark mode. |
| `theme.accent_dark` | hex | `#5b9dff` | Accent of the offline and maintenance screens in dark mode. |
| `theme.background` | hex | `#ffffff` | `background_color` (Android splash screen), background of the maskable and iOS icons. |
| `icons.source` | ?string | `null` | Default source image for `pwa:icons`. |
| `icons.path` | string | `pwa/icons` | Icon folder inside `public/`. |
| `icons.sizes` | `list<int>` | `[192, 512]` | Sizes of `icon-{size}.png`. |
| `icons.maskable_padding` | float | `0.1` | Padding of the maskable icon (the safe zone is 80%). |
| `icons.splash.background` | ?hex | `null` | Background of the iOS startup images; `null` = `theme.background`. |
| `icons.splash.dark_background` | ?hex | `null` | Adds `prefers-color-scheme: dark` variants with this background. |
| `icons.splash.icon_size` | float | `0.3` | Icon edge as a fraction of the shorter screen edge. |
| `icons.splash.orientations` | `list<string>` | `['portrait', 'landscape']` | Orientations to generate. |
| `icons.splash.devices` | `?list<string>` | `null` | Keys of `AppleDevices::SCREENS`; `null` = all iPhones and iPads. |
| `screenshots` | list | `[]` | Screenshots for the richer install dialog (`src`, `sizes`, `type`, `form_factor`), see [Manifest](manifest.md#screenshots). |

## OS integration

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `categories` | list | `[]` | Store and launcher categories, for example `['business']`. |
| `shortcuts` | list | `[]` | Icon shortcuts: `['name' => …, 'url' => …, 'icon' => …]`. |
| `share_target` | ?array | `null` | The full `share_target` object; easier with `Pwa::manifest()->shareTarget()`. |
| `file_handlers` | list | `[]` | Opening files from the OS (Chromium desktop). |
| `protocol_handlers` | list | `[]` | Custom `web+…` schemes. |
| `launch_handler` | array | `client_mode: [navigate-existing, auto]` | How an app that is already running opens. |
| `scope_extensions` | list | `[]` | Additional domains of the same app; each needs `/.well-known/web-app-origin-association`. |
| `locales` | list | `[]` | Manifest languages, including the default one: `*_localized` members (Chromium 147+) and a `?lang=` link based on the page language. |

## Service worker

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `sw.url` | string | `/sw.js` | The service worker URL. Never put a file with the same name in `public/`. |
| `sw.import` | list | `[]` | Scripts loaded with `importScripts` **before** the runtime (push system, Sentry). Their content changes the service worker version. |
| `sw.extend` | list | `[]` | Scripts loaded **after** the runtime; they get `self.PwaWorker` (custom routes and strategies). See [Service worker](service-worker.md#swextend). |
| `sw.vite_manifest` | string | `build/manifest.json` | Vite manifest in `public/` used for precaching. |
| `sw.build_directory` | string | `build` | Vite asset prefix (cache-first). |
| `sw.compiled_path` | string | `bootstrap/cache/pwa` | Where `pwa:build` stores the built service worker. |
| `sw.kill_switch_store` | ?string | `null` | Cache store for `pwa:disable`; with multiple servers, use a shared one (Redis, database). |

## Cache

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `manifest.resolvers` | list | `[]` | `ManifestResolver` classes (resolved from the container) for a per-request manifest. See [Manifest](manifest.md#resolvers-tenants-and-branding). |
| `cache.navigation_timeout` | int (s) | `3` | After how many seconds of a slow network the cached page is used. |
| `cache.rules` | list | `[]` | Cache rules (`match`, `destination`, `strategy`, `cache`, `max_entries`, `max_age`, `timeout`, `shared`). See [Cache rules](service-worker.md#cache-rules). |
| `cache.api` | list | `[]` | Shortcut: GET paths (with `*`) served network-first, for example `['/api/catalog*']`. |
| `cache.images.enabled` | bool | `true` | Default rule for images and fonts (stale-while-revalidate). |
| `cache.images.max_entries` | int | `120` | Maximum number of cached images and fonts. |
| `cache.images.max_age` | int (s) | 30 days | Older images are fetched again (offline, an older one is still used). |
| `cache.never_cache` | list | login, logout, register, broadcasting, livewire, sanctum, `/pwa/*` | Paths the service worker never stores or queues; fetch/XHR requests just pass through to the network, and the user identity is read from their responses. |

## Offline

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `offline.enabled` | bool | `true` | The offline screen and its route. |
| `offline.route` | string | `/offline` | URL of the offline screen (precached). |
| `offline.precache` | list | `[]` | Additional URLs to precache, for example `['/manifest.webmanifest', '/img/logo.svg']`. |
| `offline.queue` | bool | `true` | Queue POST/PUT/PATCH/DELETE requests during a network failure. |
| `offline.warm.urls` | `list<string>` | `[]` | Pages downloaded ahead of time; per user with `Pwa::warmUsing()`, see [Warm-up](offline.md#warm-up). |
| `offline.warm.max` | int | `20` | Maximum number of pages per warm-up. |
| `offline.warm.interval` | int | `60` | Minutes between automatic warm-ups (a sign-in always warms). |

## Session

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `session.standalone_remember` | bool | `true` | `pwa.remember` forces remember-me in the installed app. |
| `session.keepalive_minutes` | int | `10` | Keep-alive interval while the app is visible (`0` = only when the user returns). |
| `session.refresh_csrf` | bool | `true` | Refresh the CSRF token and retry `fetch` after a 419. |
| `session.remember_field` | string | `remember` | Name of the remember-me field in the login form. |

## Maintenance mode

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `maintenance.message` | ?string | `null` | Message on the maintenance screen (otherwise the translated default text). |
| `maintenance.until` | ?string | `null` | Expected end (ISO 8601); set by `pwa:down --until`. |
| `maintenance.retry` | int (s) | `60` | Default `Retry-After` and check interval (15–300 s). |
| `maintenance.refresh` | ?int | `null` | Default `--refresh` for `pwa:down`. |

## Install, telemetry, adapters

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `install.min_visits` | int | `2` | The visit from which `<x-pwa::install/>` is shown. |
| `install.dismiss_days` | int | `30` | How long to hide the prompt after the user dismisses it. |
| `install.ios_hint` | bool | `true` | The "Share → Add to Home Screen" hint on iPhone and iPad. |
| `telemetry.log` | bool | `true` | Write `TelemetryReceived` to the log; turn it off when you handle the events yourself ([Events](events.md)). |
| `telemetry.enabled` | bool | `true` | Send service worker errors to `/pwa/telemetry`. |
| `telemetry.channel` | ?string | `null` | Log channel; a channel that does not exist falls back to the default log. |
| `telemetry.max_events` | int | `20` | Maximum number of events per request. |
| `adapters.livewire` | bool | `true` | Livewire 3 adapter. |
| `adapters.inertia` | bool | `true` | Inertia adapter. |
| `adapters.reload_on_resume` | bool | `false` | Inertia `reload()` when the user returns to the app. |

## Web push

See [Push notifications](push.md).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `push.enabled` | bool | `false` | The optional push module: subscription routes, `Pwa.push`, the worker's push listeners. |
| `push.vapid.subject` | string | `APP_URL` | Contact for push services: `mailto:` or `https://`. |
| `push.vapid.public_key`, `push.vapid.private_key` | ?string | `null` | Written by `pwa:push:vapid`; new keys invalidate every subscription. |
| `push.model` | class-string | `Push\PushSubscription` | Your own model must extend it. |
| `push.table` | string | `pwa_push_subscriptions` | Table of the published migration. |
| `push.middleware` | list | `['auth']` | Middleware of the subscription routes (after `web`, throttled to 20/min). |
| `push.max_subscriptions` | int | `10` | Devices per owner; the least recently used is removed first. |
| `push.endpoints` | ?list | FCM, Mozilla, Windows, Apple | Push service hosts the server accepts and sends to (`*` wildcard); `null` = any https host. |
| `push.ttl` | int (s) | `2419200` | How long the push service keeps an undelivered message (28 days). |
| `push.urgency` | string | `normal` | `very-low`, `low`, `normal`, `high`. |
| `push.icon`, `push.badge` | ?string | `null` | Defaults for messages; `null` = `icon-192.png` and `monochrome-512.png` from `icons.path` when they exist. |
| `push.handle_in_worker` | bool | `true` | The worker shows notifications and handles clicks; `false` when a `sw.import` script does it. |
