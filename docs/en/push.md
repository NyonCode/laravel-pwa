---
title: Push notifications
description: "The interface between the PWA package and a separate push system: sw.import, events, install state and platform rules."
---

# Push notifications

The package does **not** implement push notifications: it has no VAPID keys, subscription table, UI
or sending. A separate push system builds those. The link between them is purely technical: a scope
can have only one service worker, and this package owns it. If the push system registered its own
service worker on `/`, one would overwrite the other.

## Interface

### 1. A slot in the service worker

```php
// config/pwa.php
'sw' => ['import' => ['/push-sw.js']],
```

```js
// public/push-sw.js – provided by the push system
self.addEventListener('push', (event) => {
  const data = event.data?.json() ?? {}
  event.waitUntil(self.registration.showNotification(data.title ?? 'Notification', {
    body: data.body,
    data: { url: data.url },
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  event.waitUntil(clients.openWindow(event.notification.data?.url ?? '/'))
})

self.addEventListener('pushsubscriptionchange', (event) => { /* send the new subscription to the server */ })
```

The file loads before the package runtime. Its content is part of the service worker version, so any
change reaches your users on its own (after `pwa:build`).

### 2. Install state in the browser

```js
if (Pwa.isIos() && !Pwa.isInstalled()) {
  // on iPhone/iPad, push can only be enabled in the Home Screen app
  showInstallHelp()
} else {
  button.onclick = () => pushSystem.subscribe() // only after a user gesture
}
```

### 3. Events

| Event | What the push system does with it |
| --- | --- |
| `pwa:installed` | offer to enable notifications |
| `pwa:resume` | call `pushManager.getSubscription()` and compare it with the server (subscriptions disappear on iOS) |
| `pwa:logout` | delete the subscription on the server, and optionally call `subscription.unsubscribe()` |

### 4. Badge

`Pwa.badge.set(n)` / `Pwa.badge.clear()` from the window; `self.navigator.setAppBadge(n)` from the service worker.

## What the package guarantees

- Neither service worker updates nor maintenance mode cancel the push subscription.
- Logout sends `Clear-Site-Data: "cache"`, not `"storage"`, so both the service worker and the subscription survive.
- **Exception**: `pwa:disable` unregisters the service worker, which also removes the subscriptions (the command warns you).

## Platform rules for the push system

| Platform | Rule |
| --- | --- |
| iPhone, iPad | only apps added to the Home Screen (iOS 16.4+), permission only after a tap |
| Safari (iOS 18.4+, macOS 15.5+) | supports Declarative Web Push: JSON with `web_push: 8030` is shown even without a service worker; subscribe via `window.pushManager` |
| Safari | every push must show a notification, otherwise Safari cancels the subscription after a few misses |
| Chrome, Edge, Firefox | standard Web Push through the service worker |
| subscriptions | can expire without any user action; the server side deletes them on 404/410 |

To send from the server, use for example `minishlink/web-push` or `laravel-notification-channels/webpush`.
