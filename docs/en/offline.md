---
title: Offline
description: The offline screen, pages available without a connection (pwa.cache), the API cache and the offline queue.
---

# Offline

## Three levels

1. **Offline screen**: shown whenever a navigation fails and the page is not in the cache.
2. **Pages available offline**: whatever the `pwa.cache` middleware allows is stored when the user visits it.
3. **Offline queue**: forms and API calls sent without a connection are sent later.

## Offline screen

`GET /offline` (`offline.route`), precached when the service worker installs. It has no external resources, detects
on its own when the network is back, and then loads the page the user wanted.

It includes:

- the connection status and automatic retries (2, 4, 8 … up to 30 s), verified with a request to `/pwa/ping`
  (`navigator.onLine` alone is not enough),
- a list of cached pages (up to 6, newest first),
- the number of actions waiting in the queue,
- light and dark mode, `prefers-reduced-motion`, safe-area, `aria-live`,
- texts from `pwa::messages`, colors from `theme`.

To customize it, run `php artisan vendor:publish --tag="pwa::views"` and edit `resources/views/vendor/pwa/offline.blade.php`.
Keep the inline CSS, the script and the nonce attributes.

## Pages available offline

```php
Route::middleware('pwa.cache')->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
});
```

The middleware adds `X-PWA-Cache: allow` to GET responses with status 200 only. The service worker then stores the page in
`pwa-pages-{user}` on every visit (always the latest version).

What to allow:

- overviews and detail pages the user needs at hand (orders, contacts, documents),
- pages where being a few hours out of date does no harm.

What not to allow:

- payments, admin pages, pages with one-time tokens,
- pages that change every minute (offline, the user would see a stale state with no warning).

On a shared device, the cache is cleared on logout ([Security](security.md)).

## API cache

```php
'cache' => ['api' => ['/api/catalog*', '/api/me']],
```

GET requests to these paths go to the network, and 200 responses are stored in `pwa-api-{user}`. During a network failure or
maintenance mode (503), the service worker returns the stored response. `no-store` responses are not stored.

## Offline queue

When a `fetch` with the POST, PUT, PATCH or DELETE method fails because of the network:

1. The service worker stores the request in IndexedDB (`pwa` / `queue`): the URL, the method, selected headers
   (`content-type`, `accept`, `x-csrf-token`, `x-xsrf-token`, `x-requested-with`, `x-inertia`,
   `x-livewire`) and the body.
2. It returns `202 {"queued": true}` with the `X-PWA-Queued: 1` header.
3. It registers a Background Sync (`pwa-queue`) where supported.
4. It sends clients a `queue` event with the count.

The queue is replayed on Background Sync, on the `online` event, when the user returns to the app, when the client starts, or on
`postMessage({type: 'REPLAY_QUEUE'})`. After a 419, the service worker fetches a new CSRF token from `/pwa/session` and retries
the action (including `_token` in `application/x-www-form-urlencoded` bodies). On a 503, it keeps waiting.

In your own JS:

```js
const res = await fetch('/api/notes', { method: 'POST', body: JSON.stringify(note), headers });
if (res.headers.get('X-PWA-Queued')) toast('Saved, will be sent once you are back online');
window.Pwa.on('replayed', ({ url, status }) => console.log('sent', url, status));
```

Keep in mind:

- **Classic forms** (a navigation with POST) are not queued; the service worker leaves them alone and the browser shows its own error page.
  The queue covers `fetch`/XHR (Livewire, Inertia, axios, your own JS).
- **Idempotency**: an action may be sent with a delay. When creating records, send your own ID (a UUID) from the client
  so a retry does not create a duplicate.
- **Order**: the queue is replayed in insertion order; the first network error stops the replay.
- To turn it off, set `offline.queue = false`, or add specific paths to `cache.never_cache`.
