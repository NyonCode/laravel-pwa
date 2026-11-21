---
title: Maintenance mode
description: The maintenance screen, pwa:down, service worker behavior on 503, exceptions and CSP.
---

# Maintenance mode

During `php artisan down`, users get a maintenance screen (not the offline page or a browser error),
and the installed app keeps its cache, login and push subscription.

## Enabling it

```bash
php artisan pwa:down --message="Deploying a new version" --until="15:30"
php artisan up
```

| Option | Description |
| --- | --- |
| `--message` | text on the screen (otherwise `maintenance.message` or the translated default) |
| `--until` | expected end: `15:30`, `2026-11-21 08:00`, anything `Carbon::parse` accepts |
| `--retry` | seconds for `Retry-After`; without it, derived from `--until` (clamped to 15–300 s) or `maintenance.retry` |
| `--refresh` | the `Refresh` header |
| `--secret`, `--with-secret` | bypass maintenance mode, as with `artisan down` |

`pwa:down` is a wrapper around `php artisan down --render="pwa::maintenance"`. You can also use that directly:

```bash
php artisan down --render="pwa::maintenance" --retry=120
```

## The screen

It is prerendered during `down`, so it has no request, session or auth. It shows the message, the
estimated end, a countdown to the next check and a progress indicator. Every `Retry-After` seconds
(15–300) it sends a `HEAD` request to the current URL; as soon as the response isn't a 503, it reloads
the page. It also checks when the user returns to the app and when the connection comes back.

To customize it, publish the views and edit `maintenance.blade.php`. Pass data from PHP only through
`data-*` attributes on the `#pwa-maintenance` element, not into a `<script>`: otherwise the CSP hash
changes ([Security](security.md)).

## Service worker on 503

| Situation | Behavior |
| --- | --- |
| a navigation returns 503 | the service worker shows the response, doesn't show the offline page, and stores nothing |
| cached pages | stay; a 503 doesn't overwrite them |
| GET from `cache.api` | the cached response is returned, otherwise the 503 |
| offline queue | keeps waiting on a 503 |
| open windows | receive a `maintenance` message (banner, `pwa:maintenance`) |
| the `/sw.js` update check returns 503 | the browser keeps the current service worker |
| push | delivery doesn't go through your server; a tap opens the maintenance screen |

## Exceptions

```php
$middleware->preventRequestsDuringMaintenance(except: ['manifest.webmanifest', 'sw.js', 'pwa/*']);
```

Laravel respects these exceptions even with a prerendered template. With them, the manifest, the
service worker, keep-alive and telemetry stay available. `pwa:doctor` reports missing exceptions as a warning.

## Bypassing maintenance mode

`--secret` works as it does in Laravel (`https://app.test/{secret}` sets a cookie). On iPhone, however,
the link opens in Safari, not in the Home Screen app, which has its own cookies. Test the bypass on
Android or desktop, where the app shares cookies with the browser.

## Livewire and Inertia

Actions during maintenance mode get a 503; instead of an error dialog, the adapters reload the page,
so the maintenance screen appears ([Livewire and Inertia](adapters.md)).
