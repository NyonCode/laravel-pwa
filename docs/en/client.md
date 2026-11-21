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
| `update()` | Promise | checks for a new service worker version manually |
| `keepAlive()` | `Promise<object\|null>` | calls `/pwa/session` right away |
| `version()` | `Promise<string\|null>` | version of the active service worker |
| `on(event, fn)` | function | listener without the `pwa:` prefix; returns a function that removes it |
| `badge.set(n)` | Promise | Badging API (iOS 16.4+, Android, desktop) |
| `badge.clear()` | Promise | |

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
| `pwa:queue` | `{count}` | the offline queue changed |
| `pwa:replayed` | `{url, status}` | a queued action was sent |
| `pwa:update-available` | `{worker}` | a new service worker version is waiting |
| `pwa:activated` | `{version}` | a service worker version was activated |
| `pwa:installed` | `{}` | `appinstalled` |
| `pwa:csrf` | `{token}` | a new CSRF token was applied |
| `pwa:csrf-refreshed` | `{}` | a Livewire 419 was resolved |

```js
document.addEventListener('pwa:resume', () => refreshDashboard())
document.addEventListener('pwa:logout', () => pushSystem.unsubscribe())
```

## Status bar

For offline, maintenance mode, the queue and updates, the client shows a small bar at the bottom (`.pwa-bar`, `role=status`,
`aria-live=polite`, respects the safe area). Its texts come from `pwa::messages`. It is styled through
`element.style` (CSP-safe). For your own UI, hide the bar (`.pwa-bar { display: none !important }`)
and react to the events.

## Service worker updates

A new version waits; the bar offers a "Reload" button. When the user clicks it, the client sends `SKIP_WAITING` and reloads
the page after `controllerchange`. Without a click, the new version activates once all app windows are
closed. For your own UI, use `pwa:update-available` and `detail.worker.postMessage({type: 'SKIP_WAITING'})`.

## Storage

| localStorage key | Contents |
| --- | --- |
| `pwa:visits` | number of visits |
| `pwa:install-dismissed` | time the install prompt was dismissed |

Access is wrapped in `try/catch` (private mode, full storage). Login never depends on localStorage.
