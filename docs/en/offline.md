---
title: Offline
description: The offline screen, pages available without a connection (pwa.cache), warm-up of unvisited pages, the API cache and the offline queue.
---

# Offline

## Four levels

1. **Offline screen**: shown whenever a navigation fails and the page is not in the cache.
2. **Pages available offline**: whatever the `pwa.cache` middleware allows is stored when the user visits it.
3. **Warm-up**: chosen pages are downloaded ahead of time, without a visit.
4. **Offline queue**: forms and API calls sent without a connection are sent later.

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

### Inertia

Inertia links do not navigate: they fetch the page object as JSON (`X-Inertia`). The worker treats these
visits like pages: with the same `pwa.cache` consent it stores the JSON per user next to the HTML, and
offline it answers a visit from the stored JSON or, for a page known only from a full load or the
[warm-up](#warm-up), from the page object inside its stored HTML. A link to a stored page then works
offline without reloading the app. Partial reloads (`only`, `except`) are never stored or answered from
the cache. The page components come from the Vite build, which the worker keeps once loaded.

### Livewire (`wire:navigate`)

`wire:navigate` links do not navigate either: Livewire fetches the next page's HTML
(`X-Livewire-Navigate`) and swaps it in. The worker treats these fetches like navigations: it stores the
page with the `pwa.cache` consent under the page URL, so a page opened through a link is available to a
later full load and the other way round, and answers them from the stored page when the network is slow
or down. A link to a stored page then works offline without reloading the app. For a page that was never
stored the fetch fails and Livewire falls back to a full navigation, which shows the offline screen.
Redirected answers are not stored.

## Warm-up

Pages the user has never opened can be downloaded ahead of time, so they work offline right after
sign-in:

```php
// config/pwa.php
'offline' => [
    'warm' => ['urls' => ['/dashboard', '/orders'], 'max' => 20, 'interval' => 60],
],

// AppServiceProvider::boot(): per-user pages, e.g. the latest orders
Pwa::warmUsing(fn (Request $request) => $request->user()
    ? $request->user()->orders()->latest()->limit(10)->pluck('id')->map(fn ($id) => "/orders/{$id}")
    : []);
```

The rules are the same as for visited pages:

- only responses with `X-PWA-Cache: allow` are stored, so the pages still need the `pwa.cache`
  middleware; everything else is skipped,
- pages go into `pwa-pages-{user}` of the user who asked and are deleted on logout,
- only same-origin paths outside `cache.never_cache`, no redirects (a login page is never stored).

The list is sent in the `/pwa/session` response (`no-store`), never in `sw.js`. The `warmUsing()`
callback runs on every keep-alive request, so keep it cheap. The worker downloads two pages at a time
and sends `X-PWA-Warm: 1`, so you can tell these requests apart (logs, rate limits).

When it runs:

| Trigger | Details |
| --- | --- |
| sign-in | after a sign-in navigation the worker asks `/pwa/session` itself; XHR sign-ins (Inertia, Livewire) through the client |
| keep-alive | at most once per `offline.warm.interval` minutes |
| Periodic Background Sync | installed Chromium apps with the permission granted, tag `pwa-warm` |
| `Pwa.warm()` | right away, resolves with `{count, skipped, failed}` |

Nothing is downloaded with Save-Data on or while offline.

```js
const { count } = await Pwa.warm()
Pwa.on('warmed', ({ count, skipped }) => console.log(`${count} pages ready offline`))
```

## API cache

```php
'cache' => ['api' => ['/api/catalog*', '/api/me']],
```

GET requests to these paths go to the network, and 200 responses are stored in `pwa-rule-api-{user}`. During a network failure or
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
- **Validation**: the [outbox](#outbox) keeps what the server refuses with a 4xx or 5xx status, and
  Inertia answers with validation errors. A classic `fetch` of a form without `Accept: application/json`
  gets a redirect back from Laravel on a failed validation, which the worker cannot tell from success:
  send `Accept: application/json` from your own JS.
- To turn it off, set `offline.queue = false`, or add specific paths to `cache.never_cache`.

### Outbox

When the server refuses a queued request on replay (a status from 400 up other than 419 and 503, or an
Inertia answer with validation errors), the worker keeps it in an outbox instead of dropping it
(`offline.outbox`). It is not sent again by itself. The user sees what was not saved and decides:

```blade
<x-pwa::outbox/>
```

The [component](components.md#x-pwaoutbox) lists each change with the server's message and the
validation errors, with **Try again** and **Discard**. Without it on the page the client shows a bar
("A change made offline could not be saved"). In your own UI:

```js
Pwa.on('replay-failed', ({ url, status }) => console.warn('not saved', url, status))

for (const item of await Pwa.outbox()) {
  // item: {id, url, path, method, status, reason, message, errors, data, queuedAt, failedAt}
  form.fill(item.data)        // the fields that were sent, without tokens and passwords
  await item.discard()        // or item.retry() → {ok: true} once the server accepts it
}
```

`data` holds the fields of a form or JSON body, without `_token`, `_method` and fields whose name
contains `password`, `secret`, `token`, `card`, `cvc`, `cvv` or `iban`; headers are never exposed. The
outbox is deleted with the rest of the user's data on logout or when another user signs in.

### Limits

`offline.queue_max` limits how many requests wait at once: a request beyond it is not queued, it fails
like the network and the client shows "too many changes are waiting" (`pwa:queue-full`).
`offline.queue_max_age` (minutes) stops a request that waited too long from being sent days later: on
replay it goes to the outbox with `reason: 'expired'` instead, so the user decides. Both are off
(`null`) by default.
