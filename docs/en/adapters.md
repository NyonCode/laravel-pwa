---
title: Livewire and Inertia
description: How the client handles network failures, the queue, maintenance mode, 419s and returning to the app in Livewire 3 and 4 and Inertia.
---

# Livewire and Inertia

Livewire and Inertia both load data through `fetch`/XHR, not navigation. So the service worker can't
show them the offline screen or the maintenance screen, and their own error dialogs would display raw
HTML. The package client includes two small adapters.

| Situation | Livewire 3 and 4 | Inertia |
| --- | --- | --- |
| network failure | "You are offline. The change was not sent" banner, default dialog suppressed | "You are offline" banner, `inertia:exception` suppressed |
| queued action (202) | – (Livewire requests are never queued) | banner, `pwa:queue` |
| 503 maintenance mode | `location.reload()` → maintenance screen | same |
| 419 CSRF | new token, `pwa:csrf-refreshed`, "Page expired" dialog suppressed | new token; automatic retry only via `fetch` and `window.axios` |
| returning to the app | `pwa:resume` | `pwa:resume`, plus `router.reload()` with `adapters.reload_on_resume` |

## Livewire

The adapter turns on at `livewire:init`. In Livewire 4 it uses `Livewire.interceptRequest()`
(`onError` for 419 and 503, `onFailure` for the network); in Livewire 3, `Livewire.hook('request')`.
After a 419 the client also updates the `data-csrf` attribute of the Livewire script tag.

The service worker passes Livewire requests through to the network (reading the user identity from
them) and never caches or queues them: component state might no longer match when a request is sent
late. It recognises them by the `X-Livewire` header, so this also holds for the Livewire 4 address
`/livewire-{hash}/update`; `cache.never_cache` lists both `/livewire/*` and `/livewire-*`.

`wire:navigate` links work offline for stored pages: the worker answers Livewire's page fetch from the
cache ([Offline](offline.md#livewire-wirenavigate)).

Offline state in templates: the built-in `wire:offline` keeps working.

```blade
<div wire:offline class="banner">You are offline</div>
```

After a 419, the user repeats the action; the token is already fresh. To refresh data when the user returns:

```js
document.addEventListener('pwa:resume', () => Livewire.dispatch('refresh-data'))
```

## Inertia

The adapter listens for the `inertia:invalid` DOM event (a non-Inertia response, such as a 503 or a 202
from the queue) and `inertia:exception` (a network error), and calls `preventDefault()`.

Inertia uses its own axios instance. If your app exposes it as `window.axios`, the client retries the
request after a 419 (same origin only; the token is never sent anywhere else). Otherwise it only
refreshes the token, and the user repeats the action.

Forms submitted with `useForm` are XHR, so during a network failure they are saved to the queue and sent
once the connection is back. Because they may be sent late, send your own UUID with records you create.

Offline, a link to a page with `pwa.cache` is answered from the cache, so the visit succeeds; only a page
that was never stored ends in `inertia:exception` and the banner. See
[Pages available offline](offline.md#inertia).

## Disabling them

```php
'adapters' => ['livewire' => false, 'inertia' => false],
```

Check the hook and event names against the Livewire and Inertia versions you use.
