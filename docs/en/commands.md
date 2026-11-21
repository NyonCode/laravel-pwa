---
title: Commands
description: Reference for the pwa:* Artisan commands, covering options, output, exit codes and when to run them.
---

# Commands

| Command | When |
| --- | --- |
| `pwa:install` | once, after `composer require` |
| `pwa:icons` | when your logo changes |
| `pwa:build` / `pwa:clear` | on every deployment (or via `optimize` / `optimize:clear`) |
| `pwa:doctor` | after installation, in CI and in your deploy script |
| `pwa:down` | maintenance mode |
| `pwa:disable` / `pwa:enable` | kill switch for the service worker |
| `pwa:push:vapid` | once, before enabling [push notifications](push.md) |

## pwa:install

```bash
php artisan pwa:install [--no-interaction]
```

Publishes `config/pwa.php` (tag `pwa::config`) and prints the next steps. Returns 1 if publishing fails.

## pwa:icons

```bash
php artisan pwa:icons {source?} {--background=} {--splash}
```

| Argument / option | Description |
| --- | --- |
| `source` | a PNG (gd or imagick) or an SVG (imagick only); defaults to `icons.source` |
| `--background` | background color for the maskable and iOS icons; defaults to `theme.background` |
| `--splash` | also generate iOS startup images for every iPhone and iPad (`icons.splash`) |

Writes the following to `public/{icons.path}`: `icon-{size}.png` for each of `icons.sizes`, `maskable-512.png`,
`monochrome-512.png`, `apple-touch-icon.png` (180 px), `favicon-32.png`, and `icon.svg` when the source is an SVG.
Existing files are overwritten.

With `--splash`, it also writes `splash/apple-splash-{width}x{height}.png` for each screen in
`AppleDevices::SCREENS`, portrait and landscape, plus `-dark` variants when `icons.splash.dark_background`
is set. Earlier splash images are deleted first, so the `<link>` tags always match the current set.
All 22 screen sizes in both orientations take a few seconds with GD.

## pwa:build

```bash
php artisan pwa:build
```

Builds the service worker into `{sw.compiled_path}/sw.js` from your config and the Vite manifest. Run it after
`npm run build`. It is hooked into `php artisan optimize`.

```
  INFO  Service worker 1a2b3c4d5e6f built (14 files precached) → bootstrap/cache/pwa/sw.js.
```

Without a Vite manifest, it prints a warning (the precache then holds only the offline page). An invalid Vite manifest returns 1.

## pwa:clear

Deletes the built service worker; `/sw.js` is then built at runtime. It is hooked into `optimize:clear`.

## pwa:doctor

```bash
php artisan pwa:doctor [--ci]
```

| Check | Level on failure |
| --- | --- |
| HTTPS in `APP_URL` (outside local) | error |
| `pwa.enabled` | warning |
| kill switch inactive | error |
| manifest: `name` | error |
| 192 and 512 icons | error |
| maskable icon, `apple-touch-icon`, iOS splash screens | warning |
| screenshots: `wide` and `narrow` | warning |
| screenshots: file exists, `sizes` and `type` match it | error |
| `start_url` inside `scope` | error |
| `public/sw.js` does not exist | error |
| Vite manifest | warning |
| service worker built | warning |
| built service worker matches the config and build | error |
| files from `sw.import` exist | error (another domain = warning) |
| maintenance mode exceptions | warning |
| session driver is not `array` | error |
| `pwa.remember` on some route | warning |
| `pwa.cache` on some route | warning |
| shared kill switch store | warning |
| with `push.enabled`: `minishlink/web-push`, VAPID keys and subject, subscriptions table | error |
| with `push.enabled`: `push.endpoints = null`, no worker showing notifications | warning |

Without `--ci`, it always returns 0. With `--ci`, it returns 1 on any error (warnings don't count).

## pwa:down

```bash
php artisan pwa:down {--message=} {--until=} {--retry=} {--refresh=} {--secret=} {--with-secret}
```

See [Maintenance mode](maintenance.md). An invalid `--until` returns 1; otherwise you get the exit code of `artisan down`.

## pwa:disable / pwa:enable

```bash
php artisan pwa:disable [--force]
php artisan pwa:enable
```

See [Kill switch](kill-switch.md). Without `--force`, it asks for confirmation; declining returns 1.

## pwa:push:vapid

```bash
php artisan pwa:push:vapid [--show] [--force]
```

Generates a P-256 key pair (OpenSSL, no extra package) and writes `PWA_VAPID_PUBLIC_KEY` and
`PWA_VAPID_PRIVATE_KEY` to `.env`. Existing keys are kept and the command returns 1; `--force`
replaces them (every subscription stops working until the browser resubscribes). `--show` prints the
keys for a secrets manager and writes nothing. See [Push notifications](push.md).

## php artisan about

The **Laravel PWA** section shows the version, Enabled, Kill switch, Service worker (compiled / not built) and Display.
