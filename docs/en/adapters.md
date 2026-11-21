---
title: Livewire and Inertia
description: How the client handles network failures, the queue, maintenance mode, 419s and returning to the app in Livewire 3 and Inertia.
---

# Livewire and Inertia

Livewire and Inertia both load data through `fetch`/XHR, not navigation. So the service worker can't
show them the offline screen or the maintenance screen, and their own error dialogs would display raw
HTML. The package client includes two small adapters.

| Situation | Livewire 3 | Inertia |
| --- | --- | --- |
| network failure | "You are offline" banner, default dialog suppressed | banner, `inertia:exception` suppressed |
| queued action (202) | – (Livewire paths are in `never_cache`) | banner, `pwa:queue` |
| 503 maintenance mode | `location.reload()` → maintenance screen | same |
| 419 CSRF | new token, `pwa:csrf-refreshed`, "Page expired" dialog suppressed | new token; automatic retry only via `fetch` and `window.axios` |
| returning to the app | `pwa:resume` | `pwa:resume`, plus `router.reload()` with `adapters.reload_on_resume` |

## Livewire 3

The adapter turns on at `livewire:init` and uses `Livewire.hook('request', ({ fail }) => …)`.
`/livewire/*` is in `cache.never_cache`: the service worker only passes Livewire requests through to the
network (reading the user identity from them), and never caches or queues them (component state might
no longer match when a request is sent late).

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

## Disabling them

```php
'adapters' => ['livewire' => false, 'inertia' => false],
```

Check the hook and event names against the Livewire and Inertia versions you use.
