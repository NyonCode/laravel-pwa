---
title: Service worker
description: How /sw.js is built, its lifecycle, cache rules and strategies, versioning, updates, sw.import and sw.extend.
---

# Service worker

## How it is built

`/sw.js` is a package route, not a file in `public/`. `ServiceWorkerBuilder` assembles its content:

```js
/* nyoncode/laravel-pwa service worker. Generated, do not edit. */
self.__PWA = { "precache": [...], "version": "1a2b3c4d5e6f", ... };
importScripts("/push-sw.js");          // only with sw.import
/* resources/sw/runtime.js */
importScripts("/pwa-extend.js");       // only with sw.extend
```

| State | What `/sw.js` returns |
| --- | --- |
| `pwa:build` has run | the file from `bootstrap/cache/pwa/sw.js` |
| no build | assembled at runtime on every request |
| `pwa.enabled = false` or `pwa:disable` | a self-destroying SW ([Kill switch](kill-switch.md)) |

Headers: `Cache-Control: no-cache`, `Service-Worker-Allowed: {scope}`, `ETag`.
The client registers with `updateViaCache: 'none'`, so the browser checks for a new version on
every navigation.

## Versioning

`version` is a hash of: the runtime configuration (including the precache list and the cache
rules), the `sw.import` and `sw.extend` lists, the content of those files, and the `runtime.js`
file. The precache list contains the hashed file names from Vite, so:

- a new asset build means a new SW version, which means an update for your users,
- no change to assets or config means the same file, and the browser downloads nothing.

`pwa:doctor` reports an error when the built SW does not match the current config or build.

## What is precached

| `sw.precache_vite` | Files from the Vite manifest |
| --- | --- |
| `imports` (default) | entry points, every chunk they import statically, their CSS and assets (fonts, images) |
| `entries` | entry points and their CSS only |
| `all` | every chunk, including dynamic imports (`import()`) |
| `false` | nothing from Vite |

Always precached: the offline page, the browser client (`/pwa/client.js?v=…`, also served offline
although `/pwa/*` is never cached) and `offline.precache`. With `imports`, a page that loads its
JavaScript offline also gets the shared vendor chunk it needs; with `entries`, that chunk arrives only
once the page was opened online.

```php
// config/pwa.php
'sw' => [
    'precache_vite' => 'imports',
    'precache_exclude' => ['assets/*.mp4', 'assets/hero-*.jpg'], // relative to the build directory
    'precache_warn_kb' => 5120,
],
```

Every visitor downloads the whole precache when the worker installs. `pwa:build` prints its size and
warns above `precache_warn_kb`; `pwa:doctor` reports it too. Files left out are still cached
cache-first on their first use (`/build/*`).

## Lifecycle

1. **install**: downloads the [precache](#what-is-precached) (offline page, the client,
   `offline.precache`, files from the Vite manifest). Files are fetched one by one, so a missing file does not block installation (the
   error goes to telemetry).
2. **waiting**: the new version waits while the old one is running. The client shows an update bar
   ("A new version is available.").
3. **activate** (after `SKIP_WAITING` is confirmed): deletes old `pwa-static-*` caches, caches of
   rules that no longer exist, caches of pre-1.0 versions (`pwa-api-*`, `pwa-images*`), and, when the
   version changes, the cached pages too (they reference old assets). It then enables Navigation
   Preload and claims the clients.
4. After `controllerchange`, the client reloads the page only if the user confirmed the update.

## Request handling order

| Request | Handling | Cache |
| --- | --- | --- |
| `/sw.js`; navigations to paths in `cache.never_cache` | the SW does not intervene | – |
| fetch/XHR to paths in `cache.never_cache` | straight to the network, nothing is cached or queued; only the identity headers are read | – |
| POST/PUT/PATCH/DELETE (`fetch`/XHR) | network; on a network failure, the offline queue and a `202` | IndexedDB `pwa` / `queue` |
| navigation (HTML) | network (with Navigation Preload); after `cache.navigation_timeout`, the cache if an entry exists; on error, the cache, then `/offline` | `pwa-pages-{user}` |
| `/build/*` and precache | cache-first | `pwa-static-{version}` |
| routes from `sw.extend` | the app's own handler | depends on the handler |
| other GET requests on your own domain | the first matching [rule](#cache-rules) | `pwa-rule-{name}-{user}` or `pwa-shared-{name}` |
| other domains without a route from `sw.extend` | the SW does not intervene | – |

Never cached: responses other than 200, `Cache-Control: no-store`, 503 responses, and HTML
without `X-PWA-Cache: allow`.

## Cache rules

Rules decide how the SW answers GET requests that are not navigations (API, JSON, images, files).
The first matching rule applies. Order: `cache.rules` from config, rules from code, the `cache.api`
shortcut, and finally the default rule for images and fonts.

```php
// config/pwa.php
'cache' => [
    'rules' => [
        ['match' => '/api/catalog*', 'strategy' => 'stale-while-revalidate', 'cache' => 'catalog', 'max_entries' => 200, 'max_age' => 86400],
        ['match' => '/avatars/*', 'destination' => 'image', 'strategy' => 'cache-first', 'max_age' => 604800],
        ['match' => '/api/stock', 'strategy' => 'network-first', 'timeout' => 2],
        ['match' => '/api/live*', 'strategy' => 'network-only'],
    ],
],
```

The same in code, for example in `AppServiceProvider::boot()`:

```php
use NyonCode\LaravelPwa\Facades\Pwa;

Pwa::serviceWorker()->cache('/api/catalog*')->staleWhileRevalidate()->name('catalog')->maxEntries(200)->maxAge(days: 1);
Pwa::serviceWorker()->cache('/api/stock')->networkFirst(timeout: 2);
Pwa::serviceWorker()->cache(null)->destination('font')->cacheFirst()->maxAge(days: 365);
```

| Key | Method | Meaning |
| --- | --- | --- |
| `match` | `cache('/path*')` | path with `*`, compared without the query string (each URL is still cached separately) |
| `destination` | `->destination('image', 'font')` | limits the rule to `Request.destination` |
| `strategy` | `->networkFirst()` … | see the strategy table |
| `cache` | `->name('catalog')` | cache name (`a-z`, `0-9`, `-`); derived from the rule when omitted |
| `max_entries` | `->maxEntries(200)` | once exceeded, the oldest entries are deleted |
| `max_age` | `->maxAge(days: 1)` | an older entry is not used (not even as a network-first fallback) |
| `timeout` | `->networkFirst(timeout: 2)` | network-first only: after how many seconds to answer from the cache |
| `shared` | `->shared()` | one cache for all users, see below |

| Strategy | Behavior |
| --- | --- |
| `network-first` | network; the cache only when offline, on a 503 (maintenance mode) or after `timeout` |
| `cache-first` | cache; the network only when there is no entry or after `max_age` (offline, even an older entry) |
| `stale-while-revalidate` | answers from the cache right away and refreshes it in the background for next time |
| `network-only` | always the network, caches nothing (excludes paths from a broader rule) |
| `cache-only` | only the cache or precache; `504` when there is no entry |

### Per-user caches

By default, each rule has a **separate cache per user** (`pwa-rule-{name}-{hash}`). It is deleted
when the user signs out or a different user signs in. `shared: true` creates one shared cache
(`pwa-shared-{name}`) that survives sign-out. The SW stores only responses that are not
`Cache-Control: private` and do not belong to a signed-in user (`X-PWA-User` is missing or is
`guest`). Use it for a public catalog or lookup lists, never for personal data.

The default rule for images and fonts (`cache.images`) is stale-while-revalidate with a
`max_entries` limit and a `max_age`; disable it with `'images' => ['enabled' => false]`.

`pwa:build` and `pwa:doctor` validate the rules: unknown keys or strategies, invalid names,
`timeout` on anything other than network-first, the same name for a shared and a personal cache,
and a shared rule that covers paths from `never_cache`.

## Headers the SW reads

| Header | Sent by | Effect |
| --- | --- | --- |
| `X-PWA-Cache: allow` | the `pwa.cache` middleware | the HTML may be cached |
| `X-PWA-User: {hash}` | `PwaWebMiddleware` (`web` group) | a change of user deletes the previous user's cache; the SW reads it from every response from your own domain (navigations, mutations, rules, `never_cache`), so it also catches XHR logins in Inertia and Livewire |
| `X-PWA-Logout: 1` | after the `Logout` event | deletes the cache and the queue, sends `logout` to clients |
| `Retry-After` | maintenance mode | passed on in the `maintenance` event |

## Messages

Client → SW (`navigator.serviceWorker.controller.postMessage`):

| Message | Effect |
| --- | --- |
| `{type: 'SKIP_WAITING'}` | activate the waiting version |
| `{type: 'REPLAY_QUEUE'}` | send the queue |
| `{type: 'CLEAR_USER'}` | delete the user's cache and queue |
| `{type: 'IDENTITY', user}` | identity from keep-alive (`/pwa/session`); a different user means the previous user's data is deleted |
| `{type: 'GET_VERSION'}` + `MessagePort` | returns `{version}` (`window.Pwa.version()`) |
| `{type: 'OUTBOX'}` + `MessagePort` | returns `{items}`, the refused requests (`window.Pwa.outbox()`) |
| `{type: 'RETRY', id}` + `MessagePort` | sends a refused request again; `{ok}`, `{ok: false, status}` or `{ok: false, pending: true}` while offline |
| `{type: 'DISCARD', id}` + `MessagePort` | deletes a refused request; `{ok}` |

SW → client (`{source: 'pwa', type}`): `activated`, `offline`, `maintenance`, `logout`, `queued`,
`queue`, `queue-full`, `replayed`, `replay-failed`. The client turns them into `pwa:*` DOM events ([Client](client.md)).

## sw.import

```php
'sw' => ['import' => ['/push-sw.js', 'https://browser.sentry-cdn.com/…/bundle.min.js']],
```

The scripts load before the package runtime, so their listeners (`push`, `notificationclick`,
`fetch`) run first. If an imported script calls `respondWith` in `fetch`, the package runtime does
not handle that request. Local files (`/…`) affect the SW version through their content; other
domains only through their URL.

## sw.extend

Scripts from `sw.extend` load **after** the runtime and get `self.PwaWorker`: custom routes with
the same strategies and the same per-user separation as the rules.

```php
'sw' => ['extend' => ['/pwa-extend.js']],
```

```js
// public/pwa-extend.js
const { route, strategies, cacheName } = self.PwaWorker;

// Reports: cache-first, personal cache (deleted on sign-out)
route('/reports/*', async (event) =>
  strategies.cacheFirst(event, { cacheName: await cacheName('reports'), maxEntries: 20, maxAge: 3600 }));

// Fonts from a CDN (another domain only through a RegExp or a function)
route(/^https:\/\/fonts\.gstatic\.com\//, (event) =>
  strategies.cacheFirst(event, { cacheName: 'pwa-shared-fonts', shared: true }));

// Custom response
route(({ url }) => url.pathname === '/api/health', () => new Response('{"ok":true}'));
```

| Member | Description |
| --- | --- |
| `route(match, handler)` | `match`: a path with `*` (your own domain only), a `RegExp` against the full URL, or a function `({url, request, event}) => bool`; `handler(event, context)` returns a `Response` |
| `strategies` | `networkFirst`, `cacheFirst`, `staleWhileRevalidate`, `networkOnly`, `cacheOnly`; options `{cacheName, maxEntries, maxAge, timeout, shared}` |
| `cacheName(name, {shared})` | `pwa-rule-{name}-{user}` (deleted on sign-out), with `shared` `pwa-shared-{name}` |
| `currentUser()` | the current user's hash or `guest` |
| `broadcast(message)` | sends a message to all windows (`{source: 'pwa', ...}`) |
| `version`, `config` | the SW version and `self.__PWA` |

Routes from `sw.extend` do not apply to navigations, `never_cache` or mutations, so HTML is never
cached without server consent.

## Debugging

- Chrome DevTools → Application → Service workers: status, Update on reload, Bypass for network.
- Application → Cache storage: `pwa-static-*`, `pwa-pages-*`, `pwa-rule-*`, `pwa-shared-*`, `pwa-meta`.
- Application → IndexedDB → `pwa` → `queue`: the offline queue.
- `await Pwa.version()` in the console returns the active SW version.
- SW errors go to the log with a `[pwa]` prefix ([Security](security.md#telemetry)).
