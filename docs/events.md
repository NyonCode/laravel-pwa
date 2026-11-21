---
title: Události
description: Laravel události balíčku (manifest, sestavení service workeru, nouzové vypnutí, telemetrie) a jak na ně navázat vlastní kód.
---

# Události

Balíček posílá běžné Laravel události (`Illuminate\Foundation\Events\Dispatchable`). Poslouchat je
můžete v `AppServiceProvider::boot()` přes `Event::listen()` nebo v listenerech s automatickým
objevováním. Všechny jsou ve jmenném prostoru `NyonCode\LaravelPwa\Events`.

| Událost | Kdy | Data |
| --- | --- | --- |
| `ManifestResolving` | každý požadavek na manifest, po [resolverech](manifest.md#resolvery-tenanti-a-branding) | `$request`, `$manifest` (kopie pro tento požadavek, smíte ji měnit) |
| `ServiceWorkerBuilt` | `pwa:build` zapsal service worker | `$version`, `$path`, `$precache` |
| `KillSwitchActivated` | `pwa:disable` / `Pwa::disable()` | – |
| `KillSwitchDeactivated` | `pwa:enable` / `Pwa::enable()` | – |
| `TelemetryReceived` | SW nebo klient nahlásil chyby (`POST /pwa/telemetry`) | `$source` (`sw`, `client`), `$events`, `$userAgent` |

Režim údržby (`pwa:down`) volá `artisan down`, takže platí Laravel události
`Illuminate\Foundation\Events\MaintenanceModeEnabled` a `MaintenanceModeDisabled`.

## Příklady

### Úprava manifestu

```php
use NyonCode\LaravelPwa\Events\ManifestResolving;

Event::listen(function (ManifestResolving $event) {
    if (now()->month === 12) {
        $event->manifest->themeColor('#b91c1c');
    }
});
```

Pro tenanty a jazyky je lepší [resolver](manifest.md#resolvery-tenanti-a-branding): umí si předat
parametry z vykreslené stránky. Událost se hodí pro drobné globální úpravy.

### Po sestavení service workeru

```php
use NyonCode\LaravelPwa\Events\ServiceWorkerBuilt;

Event::listen(function (ServiceWorkerBuilt $event) {
    Http::post(config('services.cdn.purge_url'), ['paths' => ['/sw.js']]);
    Log::info("PWA {$event->version}: ".count($event->precache).' souborů v precache');
});
```

### Nouzové vypnutí

```php
use NyonCode\LaravelPwa\Events\KillSwitchActivated;

Event::listen(fn (KillSwitchActivated $event) => Notification::route('slack', config('services.slack.ops'))
    ->notify(new PwaKillSwitchUsed()));
```

### Telemetrie do Sentry

```php
use NyonCode\LaravelPwa\Events\TelemetryReceived;

Event::listen(function (TelemetryReceived $event) {
    foreach ($event->events as $error) {
        \Sentry\captureMessage("[pwa:{$event->source}] {$error['type']}: {$error['message']}");
    }
});
```

Balíček události ve výchozím stavu zapisuje do logu (listener `LogTelemetry`, kanál
`telemetry.channel`). Pokud je zpracováváte sami, vypněte to: `'telemetry' => ['log' => false]`.
Události obsahují jen technická data (typ, zpráva, stack, verze SW, režim zobrazení, User-Agent),
žádné osobní údaje.

## Testování

```php
Event::fake([ServiceWorkerBuilt::class]);

$this->artisan('pwa:build')->assertSuccessful();

Event::assertDispatched(ServiceWorkerBuilt::class, fn ($event) => $event->precache !== []);
```
