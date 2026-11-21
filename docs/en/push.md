---
title: Push notifications
description: "Optional web push module: VAPID keys, subscriptions per device, a Laravel notification channel, the worker's push handling, Pwa.push in the browser, Declarative Web Push and WebPush::fake(); or bring your own push system through sw.import."
---

# Push notifications

The package has an optional web push module, off by default. It covers the whole path: VAPID keys,
one subscription per device, a channel for Laravel notifications, a worker that shows them and a
browser API for the opt-in. If you already have a push system, keep it and use
[your own push system](#your-own-push-system) instead.

## Setup

```bash
composer require minishlink/web-push
php artisan pwa:push:vapid
php artisan vendor:publish --tag="pwa::migrations"
php artisan migrate
```

```dotenv
PWA_PUSH_ENABLED=true
PWA_VAPID_SUBJECT=mailto:ops@example.com
# written by pwa:push:vapid; in production, copy them to the server's environment
PWA_VAPID_PUBLIC_KEY=…
PWA_VAPID_PRIVATE_KEY=…
```

```php
use NyonCode\LaravelPwa\Push\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasPushSubscriptions, Notifiable;
}
```

Then run `php artisan pwa:build` (the worker carries the public key) and `php artisan pwa:doctor`,
which checks the library, the keys, the subject and the table.

| Requirement | Why |
| --- | --- |
| `minishlink/web-push` ^10 or ^11 | encryption (RFC 8291) and VAPID signatures (RFC 8292) |
| HTTPS | browsers only allow push on secure origins |
| a signed-in user | subscriptions belong to an Eloquent model (`subscribable`, a morph relation) |

Owners with UUID or ULID keys: change `morphs()` to `uuidMorphs()` or `ulidMorphs()` in the published
migration.

## Subscribing in the browser

Ask after a click, never on page load: browsers block or bury prompts nobody asked for.

```js
button.addEventListener('click', async () => {
  const state = await Pwa.push.state()
  if (state === 'install-required') return showInstallHelp() // iPhone, iPad: Home Screen app only
  if (state === 'denied') return showBlockedHelp()
  await Pwa.push.subscribe()
})

Pwa.on('push', ({ state }) => (button.hidden = state === 'subscribed'))
```

Or drop in [`<x-pwa::push-toggle/>`](components.md#x-pwapush-toggle): one button that does the same,
with texts for the blocked and the iPhone cases.

| `Pwa.push` | Returns | Description |
| --- | --- | --- |
| `supported()` | bool | push enabled on the server and in this browser |
| `permission()` | string | `default`, `granted`, `denied`, `unsupported` |
| `state()` | `Promise<string>` | `subscribed`, `unsubscribed`, `denied`, `install-required`, `unsupported` |
| `subscribe()` | `Promise<PushSubscription\|null>` | asks for permission, subscribes, stores the subscription on the server |
| `unsubscribe()` | `Promise<bool>` | removes it on the server and in the browser |
| `toggle()` | Promise | `unsubscribe()` when subscribed, otherwise `subscribe()` |
| `subscription()` | `Promise<PushSubscription\|null>` | the browser's current subscription |

The client keeps the subscription in step on its own:

- once a day (on keep-alive) it confirms the subscription with the server; a changed endpoint or a
  new VAPID key is stored again;
- when the browser dropped the subscription (iOS does that), it emits `pwa:push` with
  `{state: 'unsubscribed', lost: true}`, so you can offer the button again;
- on logout, or when another user signs in, it unsubscribes the browser.

## Sending

```php
use Illuminate\Notifications\Notification;
use NyonCode\LaravelPwa\Push\WebPushChannel;
use NyonCode\LaravelPwa\Push\WebPushMessage;

class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class]; // or 'webpush'
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return WebPushMessage::create('Order shipped')
            ->body("Order {$this->order->number} is on its way.")
            ->url(route('orders.show', $this->order))
            ->tag("order-{$this->order->id}")
            ->action('track', 'Track', route('orders.track', $this->order));
    }
}

$user->notify(new OrderShipped($order));
```

Without a notification class:

```php
use NyonCode\LaravelPwa\Facades\WebPush;

WebPush::send($user, WebPushMessage::create('Export ready')->url('/exports'));
WebPush::send($user->pushSubscriptions()->latest()->first(), $message); // one device
```

| `WebPushMessage` | Effect |
| --- | --- |
| `title()`, `body()` | the text (a title is required) |
| `icon()`, `badge()`, `image()` | defaults: `pwa.push.icon` (or `icon-192.png`), `pwa.push.badge` (or `monochrome-512.png`) |
| `url()` | page opened on click; only URLs of this origin, anything else opens the scope |
| `action($id, $title, $url = null)` | a button; its own URL or the message URL |
| `tag($tag, renotify: false)` | replaces an earlier notification with the same tag |
| `requireInteraction()`, `silent()`, `lang()`, `dir()`, `timestamp()` | as in `showNotification()` |
| `appBadge($n)` | number on the app icon, `0` clears it |
| `data([...])` | extra data for your own `sw.extend` code |
| `ttl()`, `urgency()`, `topic()` | delivery: defaults `pwa.push.ttl` (28 days), `pwa.push.urgency` |
| `declarative()` | [Declarative Web Push](#declarative-web-push) |
| `public()` | may be shown to anyone on the device (see [Shared devices](#shared-devices)) |

The encrypted payload is limited to 4078 bytes; a longer message throws before anything is sent.
Keep the details on the page the notification opens.

## Delivery results

| Push service answers | The package |
| --- | --- |
| 2xx | sets `last_used_at` |
| 404, 410 | deletes the subscription, dispatches `PushSubscriptionExpired` |
| anything else (413, 429, 5xx, network) | keeps it, dispatches `PushFailed` |

`WebPush::send()` and the channel return a list of `Push\PushReport` (`endpoint`, `success`, `status`,
`reason`). Subscriptions whose browser announced an `expirationTime` are removed by
`php artisan model:prune --model="NyonCode\LaravelPwa\Push\PushSubscription"`.

## Shared devices

Push follows the same rule as the caches: one user's data never reaches the next person on the device.

- Logout removes the subscriptions made in that session on the server and in the browser.
- Each message carries the recipient's user hash. When someone else is signed in on the device (or
  nobody is), the worker shows a neutral notification ("You have a new notification. Open the app to
  see it.", `pwa::messages.push_fallback`) instead of the content. Safari requires a notification for
  every push, so it is never skipped.
- A subscription moves to the user who subscribes on the device; the worker's automatic renewal
  (`pushsubscriptionchange`) is accepted only in place of an endpoint the signed-in user owns.
- Messages for a non-user notifiable (a team, a channel) and `public()` messages are shown to anyone.

## Server-side safety

| Risk | What the package does |
| --- | --- |
| a user registers an internal URL as the endpoint, and the server POSTs to it | only `https` endpoints on the hosts in `pwa.push.endpoints` (FCM, Mozilla, Windows, Apple) are accepted and sent to |
| a third party subscribes a victim's account to its own endpoint | the routes are in the `web` group with CSRF and `auth`, throttled to 20 per minute |
| one account fills the table | `pwa.push.max_subscriptions` (10) per owner; the least recently used goes first |
| leaked subscription keys | `public_key` and `auth_token` are `$hidden`; without the VAPID private key nobody can send |

Rotating VAPID keys (`pwa:push:vapid --force`) invalidates every subscription. Browsers resubscribe
with the new key on their next visit; until then they receive nothing.

## Declarative Web Push

Safari 18.4+ (iOS, iPadOS) and macOS 15.5+ show declarative messages without starting the worker,
which is more reliable on iOS. Other browsers pass them to the worker, which shows them the same way.

```php
WebPushMessage::create('Order shipped')->url('/orders/7')->appBadge(1)->declarative();
```

A declarative message needs `url()`. The browser shows it as sent, so the per-user check of the
worker does not apply: use it for messages that are safe on a shared device.

## In the worker

With `pwa.push.handle_in_worker = true` (default), the worker:

- shows every push (`push`), also plain text and empty ones;
- on click focuses a window with the URL, navigates a window of the app, or opens a new one
  (`notificationclick`); the page gets `pwa:notification-click` with `{url, action}`;
- renews a rotated subscription and stores it for the same user (`pushsubscriptionchange`,
  `pwa:push-renewed`);
- tells open pages about each push (`pwa:push-received` with `{tag}`).

Set `handle_in_worker` to `false` when your own `sw.import` script shows the notifications; the
subscription routes, the channel and `Pwa.push` keep working.

## Testing

```php
use NyonCode\LaravelPwa\Facades\WebPush;

WebPush::fake();

$user->notify(new OrderShipped($order));

WebPush::assertSentTo($user, fn (WebPushMessage $message) => $message->getTitle() === 'Order shipped');
WebPush::assertNotSentTo($otherUser);
WebPush::assertSentCount(1);
WebPush::assertNothingSent();
```

The fake builds every message (title, payload size) but sends nothing. `Notification::fake()` works
as usual when you only care that a notification was dispatched.

## Your own push system

Already using `laravel-notification-channels/webpush`, Firebase or a SaaS? Leave `push.enabled` off
and plug your code into the package's worker. A scope can have only one service worker, and this
package owns it: a second worker registered on `/` would replace it.

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
- Logout sends `Clear-Site-Data: "cache"`, not `"storage"`, so the service worker survives (the built-in
  module removes that user's subscriptions itself).
- **Exception**: `pwa:disable` unregisters the service worker, which also removes the subscriptions (the command warns you).

## Platform rules

| Platform | Rule |
| --- | --- |
| iPhone, iPad | only apps added to the Home Screen (iOS 16.4+), permission only after a tap; `Pwa.push.state()` returns `install-required` in Safari |
| Safari (iOS 18.4+, macOS 15.5+) | Declarative Web Push: shown even without running the worker |
| Safari | every push must show a notification, otherwise Safari cancels the subscription after a few misses |
| Chrome, Edge, Firefox | standard Web Push through the service worker |
| subscriptions | can expire without any user action; the package deletes them on 404/410 |
