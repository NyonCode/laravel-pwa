---
title: Troubleshooting
description: Common problems with installation, updates, caching, login and maintenance mode, and how to fix them.
---

# Troubleshooting

Always start with `php artisan pwa:doctor` and Chrome DevTools → Application.

## The app cannot be installed

| Cause | Fix |
| --- | --- |
| 192/512 icons are missing | `php artisan pwa:icons …` |
| HTTP instead of HTTPS | use HTTPS, configure trusted proxies |
| `start_url` is outside `scope` | fix the config |
| service worker disabled (`local`) | `PWA_ENABLED=true` |
| iPhone | installation is manual only, via Share → Add to Home Screen |
| already installed | `Pwa.isInstalled()` |

DevTools → Application → Manifest shows the exact reason.

## Users see an old version

1. Did `php artisan pwa:build` run after `npm run build`? `pwa:doctor` reports "Built service worker is up to date".
2. Make sure your CDN does not cache `/sw.js`.
3. A new version waits until the user clicks "Reload" or closes all app windows.
4. In an emergency: `php artisan pwa:disable`, deploy the fix, `php artisan pwa:enable`.

## A page is not available offline

- Does the route have the `pwa.cache` middleware? (`curl -I` must show `X-PWA-Cache: allow`)
- Does it respond with 200 and without `Cache-Control: no-store`?
- Did the user visit it online after the service worker was installed?
- Is the path listed in `cache.never_cache`?
- Stored pages are deleted after a service worker update (they reference old assets), so they must be visited again.

## The browser error page appears offline instead of the offline page

- The service worker does not control the page yet (first visit); it will after a reload.
- It was a classic POST form, which the service worker does not handle.
- `offline.enabled` is `false`, or `/offline` failed during precaching (log entry `[pwa] precache`).

## Users get logged out after a few days

- Is `pwa.remember` on the login route? `pwa:doctor` warns when it is missing.
- Does the form send a field named after `session.remember_field`?
- Make sure the session driver is not `array`/`cookie`.
- iPhone: log in from the home screen app, not in Safari. See [Persistent login](session.md).

## 419 Page Expired

- Is `session.refresh_csrf` enabled?
- Custom HTTP client (not `fetch`, not `window.axios`): retry the request after `pwa:csrf`.
- Livewire: after a 419, the token is refreshed and you only need to retry the action.

## Queued actions are not sent

- The queue is replayed after `online`, when the user returns to the app, or on Background Sync (iOS doesn't support it).
- A 503 (maintenance mode) postpones the replay.
- Queue contents: DevTools → IndexedDB → `pwa` → `queue`.

## The offline page appears during maintenance mode

- Does the server really respond with 503? The service worker treats any other code (such as a 502 from a proxy) as a network failure.
- Did `pwa:down` use `--render="pwa::maintenance"`? (Otherwise you get the default Laravel 503 page.)

## pwa:disable does not work

- Does `public/sw.js` exist? Delete it (`pwa:doctor` reports an error).
- Multiple servers and the `file` cache store? Set `PWA_KILL_SWITCH_STORE=redis`.
- Did `cache:clear` run? The flag lives in the cache; check `php artisan about`.

## Edge on Windows does not show the Back/Reload buttons

- `override`/`adaptive` strategy: the manifest must contain `minimal-ui` (`curl /manifest.webmanifest`).
- An app installed before the change updates its manifest only on the next check (roughly daily);
  to speed it up, uninstall and reinstall it.
- With WCO, the buttons are drawn by `<x-pwa::nav/>`.

## Debugging the service worker

```js
await Pwa.version()                       // version of the active service worker
(await navigator.serviceWorker.getRegistrations()).length
await caches.keys()
```

Chrome: `chrome://serviceworker-internals`, `chrome://web-app-internals`.
