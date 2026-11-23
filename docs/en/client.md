---
title: JavaScript client
description: The window.Pwa API, pwa:* events, the status bar, installation, badges and how the client registers the service worker.
---

# JavaScript client

`<x-pwa::head/>` loads `/pwa/client.js` as an ES module. It has no dependencies and needs no build step.

## What it does on load

1. Increments the visit counter (localStorage `pwa:visits`).
2. Sets the `pwa_display` cookie with the display mode.
3. Wraps `fetch` and `window.axios` to retry after a 419 (with `session.refresh_csrf`, same-origin requests only).
4. Enables the Livewire and Inertia adapters.
5. Registers the service worker, or unregisters it (`enabled: false`).
6. Starts the keep-alive interval.
7. Dispatches `pwa:ready`.

## `window.Pwa`

| Member | Returns | Description |
| --- | --- | --- |
| `config` | object | configuration from `#pwa-config` |
| `displayMode()` | string | `window-controls-overlay`, `tabbed`, `fullscreen`, `standalone`, `minimal-ui`, `browser` |
| `isInstalled()` | bool | `displayMode() !== 'browser'` (on iOS also `navigator.standalone`) |
| `isIos()` | bool | iPhone, iPod, iPad (including an iPad that identifies as a Mac) |
| `install()` | `Promise<string>` | `accepted`, `dismissed`, `unavailable`; uses `navigator.install()` or the stored prompt |
| `canInstall()` | bool | installing makes sense now: a prompt is available, or the iOS instructions apply |
| `update()` | Promise | checks for a new service worker version manually |
| `updateAvailable()` | bool | a new version waits |
| `applyUpdate()` | bool | activates the waiting version and reloads the page once it takes over; `false` when nothing waits |
| `refreshUi()` | – | shows or hides `[data-pwa-*]` elements added after load (install, update, push, outbox) |
| `keepAlive()` | `Promise<object\|null>` | calls `/pwa/session` right away |
| `warm()` | `Promise<object\|null>` | downloads the warm-up pages now; `{count, skipped, failed}` ([Offline](offline.md#warm-up)) |
| `outbox()` | `Promise<object[]>` | changes the server refused on replay, each with `retry()` and `discard()` ([Offline](offline.md#outbox)) |
| `version()` | `Promise<string\|null>` | version of the active service worker |
| `on(event, fn)` | function | listener without the `pwa:` prefix; returns a function that removes it |
| `badge.set(n)` | Promise | Badging API (iOS 16.4+, Android, desktop) |
| `badge.clear()` | Promise | |
| `push.*` | | `supported()`, `permission()`, `state()`, `subscribe()`, `unsubscribe()`, `toggle()`, `subscription()`; see [Push notifications](push.md#subscribing-in-the-browser) |

```js
if (!Pwa.isInstalled() && Pwa.isIos()) showIosHelp()

const off = Pwa.on('queue', ({ count }) => (queueBadge.textContent = count || ''))
Pwa.badge.set(unreadCount)
console.log(await Pwa.version())
```

## Events

Every event is dispatched as a `CustomEvent` on `document` (`pwa:name`) and is also available through `Pwa.on('name')`.

| Event | `detail` | When |
| --- | --- | --- |
| `pwa:ready` | `{displayMode}` | the client has loaded |
| `pwa:resume` | `{displayMode}` | the user returns to the app (visibility, bfcache) |
| `pwa:online` | `{}` | the connection is restored |
| `pwa:offline` | `{}` | the service worker could not complete a navigation or action because of the network |
| `pwa:maintenance` | `{retryAfter}` | the service worker received a 503 |
| `pwa:logout` | `{}` | the service worker processed a logout |
| `pwa:queue` | `{count, failed}` | the offline queue changed (`count` waiting, `failed` in the outbox) |
| `pwa:queue-full` | `{count}` | an action was not queued because of `offline.queue_max` |
| `pwa:replayed` | `{url, status}` | a queued action was sent (any status) |
| `pwa:replay-failed` | `{id, url, method, status, reason, message, errors, kept}` | the server refused a queued action, or it expired; `kept` when it stays in the outbox |
| `pwa:update-available` | `{worker}` | a new service worker version is waiting |
| `pwa:activated` | `{version}` | a service worker version was activated |
| `pwa:installable` | `{prompt, ios}` | installation became possible (a prompt, or the iOS instructions) |
| `pwa:installed` | `{}` | `appinstalled` |
| `pwa:csrf` | `{token}` | a new CSRF token was applied |
| `pwa:csrf-refreshed` | `{}` | a Livewire 419 was resolved |
| `pwa:identity` | `{user}` | another user signed in (user hash) |
| `pwa:warmed` | `{count, skipped, failed}` | a warm-up finished |
| `pwa:push` | `{state, lost?, error?}` | the push subscription changed (`subscribed`, `unsubscribed`, `denied`) |
| `pwa:push-received` | `{tag}` | the worker showed a push notification |
| `pwa:push-renewed` | `{}` | the worker renewed a rotated subscription |
| `pwa:notification-click` | `{url, action}` | the user clicked a notification |

```js
document.addEventListener('pwa:resume', () => refreshDashboard())
document.addEventListener('pwa:logout', () => pushSystem.unsubscribe())
```

## Status bar

For offline, maintenance mode, the queue and updates, the client shows a small bar at the bottom (`.pwa-bar`, `role=status`,
`aria-live=polite`, respects the safe area). Its texts come from `pwa::messages`. It is styled through
`element.style` (CSP-safe). For your own UI, hide the bar (`.pwa-bar { display: none !important }`)
and react to the events. For updates, `updates.bar = false` or an `<x-pwa::update-toast/>` on the page
is enough.

## Service worker updates

A new version waits until the user accepts it; until then the page keeps the version it loaded with.
The bar (or [`<x-pwa::update-toast/>`](components.md#x-pwaupdate-toast)) offers to update. On click, the
client sends `SKIP_WAITING` to the waiting worker and reloads the page after `controllerchange`. Without a
click, the new version activates once all app windows are closed.

For your own UI, set `updates.bar` to `false`, listen to `pwa:update-available` and call `Pwa.applyUpdate()`:

```js
Pwa.on('update-available', () => showMyToast({ onAccept: () => Pwa.applyUpdate() }))
```

Do not post `SKIP_WAITING` yourself: the client would not know to reload, and the page would run
old JavaScript against the new worker.

## Storage

| localStorage key | Contents |
| --- | --- |
| `pwa:visits` | number of visits |
| `pwa:install-dismissed` | time the install prompt was dismissed |
| `pwa:warm` | user hash and time of the last warm-up |
| `pwa:push` | endpoint, user hash and time of the last confirmation of this browser's push subscription |

In sessionStorage (per tab), `pwa:update-dismissed` remembers "Later" on the update toast: the toast stays
hidden on the next pages of this tab and comes back with a newer version or in a new tab.

Access is wrapped in `try/catch` (private mode, full storage). Login never depends on localStorage.
