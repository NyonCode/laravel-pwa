---
name: laravel-pwa
description: Set up or change the Progressive Web App of a Laravel application that uses nyoncode/laravel-pwa. Use when touching the web app manifest, display mode (minimal-ui on desktop, standalone on phones), the service worker or /sw.js, offline pages (pwa.cache), the offline queue, install prompts, <x-pwa::head/>, <x-pwa::nav/>, <x-pwa::install/>, maintenance mode for the PWA (pwa:down), the kill switch (pwa:disable), staying logged in in the installed app (pwa.remember, CSRF 419), or wiring a push system into the PWA's service worker (sw.import).
---

# Laravel PWA

The package owns the manifest (`/manifest.webmanifest`) and the only service worker on scope `/`
(`/sw.js`, a route — never a file in `public/`). The application decides three things: which pages
may be stored offline (`pwa.cache`), where login happens (`pwa.remember`), and what extra code runs
in the worker (`config('pwa.sw.import')`).

## Read this first

The complete reference ships with the installed release — read it before changing anything:

```
vendor/nyoncode/laravel-pwa/ai/AGENTS.md
```

If the package's MCP server is registered, prefer it: `search_docs` for a concept, `describe_config`
for a config key and its default, `describe_api` for a PHP signature, `describe_client` for
`window.Pwa` and `pwa:*` events, `list_commands` for artisan commands.

## The shape of the work

```blade
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<x-pwa::head/>
```

```php
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('pwa.remember');
Route::middleware(['auth', 'pwa.cache'])->get('/dashboard', DashboardController::class);

// AppServiceProvider::boot()
Pwa::manifest()->shortcut('New order', '/orders/create')->windowControlsOverlay();
```

Deploy: `npm run build` → `php artisan pwa:build` → `php artisan pwa:doctor --ci`.

## Five things that are easy to get wrong

**One service worker.** Never register another worker on `/` and never create `public/sw.js`. Push,
Sentry or any worker code goes into a public JS file listed in `pwa.sw.import`.

**Push is not part of this package.** It provides only the `sw.import` slot, `Pwa.isInstalled()`,
and the `pwa:installed` / `pwa:resume` / `pwa:logout` events. On iPhone push can be enabled only
inside the Home Screen app, after a user gesture.

**Offline is opt-in.** No HTML is stored without `pwa.cache`. Do not put it on payment, admin or
one-time-token pages. Only `fetch`/XHR mutations are queued offline; classic form POSTs are not.

**Logout must not clear storage.** The package sends `Clear-Site-Data: "cache"` only; adding
`"storage"` unregisters the worker and kills push subscriptions.

**The kill switch is the escape hatch.** A broken worker survives server fixes. `php artisan
pwa:disable` serves a self-destroying worker on the next visit; `pwa:enable` restores it. It needs a
shared cache store on multiple servers.

## Verify, don't assume

Run `php artisan pwa:doctor` after every change and `--ci` in deploys. Then check in a browser:
DevTools → Application → Service workers (activated `/sw.js`), Network → Offline (cached page loads,
uncached page shows the offline screen).
