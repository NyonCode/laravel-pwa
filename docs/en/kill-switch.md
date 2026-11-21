---
title: Kill switch
description: pwa:disable and pwa:enable remove the service worker from every device without a deployment.
---

# Kill switch

A broken service worker is worse than a broken server: it stays in browsers and keeps serving pages
even after you fix the server. That is why the package can turn the service worker off on every
device with a single command.

## Usage

```bash
php artisan pwa:disable          # asks for confirmation; --force skips the question
php artisan pwa:enable
```

## What happens

1. A flag is stored in the cache (`sw.kill_switch_store`, otherwise the default store). No deployment needed.
2. `/sw.js` starts returning a minimal service worker (`resources/sw/kill-switch.js`):
   - on install, it calls `skipWaiting()`,
   - on activate, it deletes all `pwa-*` caches, calls `registration.unregister()` and reloads open
     windows from the network.
3. `<x-pwa::head/>` passes `enabled: false` to the client; the client removes any registrations itself.
4. The manifest stays, and the installed app keeps working online.

The browser checks `/sw.js` on every navigation (`updateViaCache: 'none'`, `no-cache`), so each
device drops the service worker on its next visit.

## Caveats

- **Push subscriptions**: unregistering the service worker cancels push subscriptions in browsers.
  The command warns you about this when `sw.import` is set. After `pwa:enable`, users have to opt in
  to notifications again (your push system should restore the subscription on `pwa:resume`).
- **Multiple servers**: the flag must live in shared storage (Redis, database, Memcached). With a
  `file` or `array` store, the command warns you.
- **`public/sw.js`**: if this file exists, the web server serves it instead of the route and the
  kill switch does not work. `pwa:doctor` reports this as an error.
- **`php artisan cache:clear`** deletes the flag (it lives in the cache). After clearing the cache,
  check `php artisan about` (the Laravel PWA section → Kill switch).

## Local development

With `PWA_ENABLED=false` (the default in `local`), `/sw.js` returns the same self-destroying service
worker, so a service worker left over from earlier testing removes itself from your browser.
