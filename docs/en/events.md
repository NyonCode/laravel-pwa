---
title: Events
description: The package's Laravel events (manifest, service worker build, kill switch, telemetry) and how to hook your own code into them.
---

# Events

The package dispatches regular Laravel events (`Illuminate\Foundation\Events\Dispatchable`). You can listen to them
in `AppServiceProvider::boot()` with `Event::listen()` or in auto-discovered listeners. They all live in the
`NyonCode\LaravelPwa\Events` namespace.

| Event | When | Data |
| --- | --- | --- |
| `ManifestResolving` | on every manifest request, after the [resolvers](manifest.md#resolvers-tenants-and-branding) | `$request`, `$manifest` (a copy for this request; you may modify it) |
| `ServiceWorkerBuilt` | `pwa:build` wrote the service worker | `$version`, `$path`, `$precache` |
| `KillSwitchActivated` | `pwa:disable` / `Pwa::disable()` | – |
| `KillSwitchDeactivated` | `pwa:enable` / `Pwa::enable()` | – |
| `TelemetryReceived` | the service worker or client reported errors (`POST /pwa/telemetry`) | `$source` (`sw`, `client`), `$events`, `$userAgent` |
| `PushSubscribed` | a browser stored or renewed its [push](push.md) subscription | `$subscription` |
| `PushUnsubscribed` | a subscription was removed (unsubscribe, logout, device limit) | `$subscription` |
| `PushSubscriptionExpired` | the push service answered 404/410; the subscription was deleted | `$subscription`, `$report` |
| `PushFailed` | delivery failed otherwise (413, 429, 5xx, network, endpoint not allowed) | `$subscription`, `$report` |

Maintenance mode (`pwa:down`) calls `artisan down`, so Laravel's own
`Illuminate\Foundation\Events\MaintenanceModeEnabled` and `MaintenanceModeDisabled` events apply.

## Examples

### Modifying the manifest

```php
use NyonCode\LaravelPwa\Events\ManifestResolving;

Event::listen(function (ManifestResolving $event) {
    if (now()->month === 12) {
        $event->manifest->themeColor('#b91c1c');
    }
});
```

For tenants and languages, a [resolver](manifest.md#resolvers-tenants-and-branding) is a better fit: it can receive
parameters from the rendered page. The event suits small global tweaks.

### After the service worker is built

```php
use NyonCode\LaravelPwa\Events\ServiceWorkerBuilt;

Event::listen(function (ServiceWorkerBuilt $event) {
    Http::post(config('services.cdn.purge_url'), ['paths' => ['/sw.js']]);
    Log::info("PWA {$event->version}: ".count($event->precache).' files precached');
});
```

### Kill switch

```php
use NyonCode\LaravelPwa\Events\KillSwitchActivated;

Event::listen(fn (KillSwitchActivated $event) => Notification::route('slack', config('services.slack.ops'))
    ->notify(new PwaKillSwitchUsed()));
```

### Telemetry to Sentry

```php
use NyonCode\LaravelPwa\Events\TelemetryReceived;

Event::listen(function (TelemetryReceived $event) {
    foreach ($event->events as $error) {
        \Sentry\captureMessage("[pwa:{$event->source}] {$error['type']}: {$error['message']}");
    }
});
```

By default, the package writes these events to the log (the `LogTelemetry` listener, channel
`telemetry.channel`). If you handle them yourself, turn that off: `'telemetry' => ['log' => false]`.
The events contain only technical data (type, message, stack, service worker version, display mode, User-Agent)
and no personal data.

## Testing

```php
Event::fake([ServiceWorkerBuilt::class]);

$this->artisan('pwa:build')->assertSuccessful();

Event::assertDispatched(ServiceWorkerBuilt::class, fn ($event) => $event->precache !== []);
```
