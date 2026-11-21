---
title: Installation
description: Step-by-step setup of the package, layout, icons, routes, maintenance mode exceptions and deployment.
---

# Installation

After these steps your app is installable, works offline, and `php artisan pwa:doctor` reports
all green.

## Requirements

| What | Version |
| --- | --- |
| PHP | 8.2+ (Laravel 13 requires 8.3+) |
| Laravel | 12.x or 13.x |
| HTTPS | required in production; `localhost` is exempt |
| Vite | recommended, for precaching assets |
| `ext-gd` or `ext-imagick` | only for `pwa:icons` (SVG requires imagick) |

## 1. Composer

```bash
composer require nyoncode/laravel-pwa
php artisan pwa:install
```

`pwa:install` publishes `config/pwa.php` and prints the next steps. The service provider registers
itself (package discovery).

## 2. Layout

Add this to the `<head>` of your main layout:

```blade
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<x-pwa::head/>
```

- `viewport-fit=cover` lets content extend under the iPhone display notch; handle the margins with
  `env(safe-area-inset-*)`.
- `csrf-token` is optional, but the client writes the refreshed token into it.

Optionally, add these to your app header:

```blade
<x-pwa::nav/>
<x-pwa::install class="btn btn-primary">Install app</x-pwa::install>
```

See [Components](components.md) for details.

## 3. Icons

```bash
php artisan pwa:icons resources/pwa/icon.png
```

Ideally the source is 1024×1024, square, with the logo centered. The command creates
`public/pwa/icons/` with 192 and 512 px icons, a maskable icon for Android, `apple-touch-icon.png`
(180 px, with a background), a monochrome icon and a favicon. Add `--splash` for iOS startup
images. The manifest lists only icons that exist on disk.

## 4. Routes

```php
// routes/web.php

// Login: always "remember me" in the installed app
Route::post('/login', [LoginController::class, 'store'])->middleware('pwa.remember');

// Pages that should work without a connection
Route::middleware(['auth', 'pwa.cache'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/orders', [OrderController::class, 'index']);
});
```

With the starter kits, Jetstream or Fortify, see [Recipes](recipes.md): Fortify takes the middleware
in `config/fortify.php`, Livewire logins set `remember` in the component. See also
[Persistent login](session.md).

## 5. Maintenance mode exceptions

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->preventRequestsDuringMaintenance(except: [
        'manifest.webmanifest',
        'sw.js',
        'pwa/*',
    ]);
})
```

Everything works without them too, but keep-alive and the manifest return 503 during maintenance.
See [Maintenance mode](maintenance.md).

## 6. Deployment

After `npm run build` in your deploy script:

```bash
php artisan pwa:build
php artisan pwa:doctor --ci
```

`pwa:build` is also hooked into `php artisan optimize`. See [Deployment](deployment.md).

## 7. Verification

```bash
php artisan pwa:doctor
```

It checks HTTPS, icons, scope, the service worker build, maintenance mode exceptions, the session
and more. Fix the errors and consider the warnings.

## Local development

With `APP_ENV=local`, the service worker is disabled by default so it does not cache files from the
Vite dev server. To test the PWA locally, set:

```dotenv
PWA_ENABLED=true
```

and run `npm run build` instead of `npm run dev`. To test on a phone over the network you need
HTTPS (Herd, Valet, a tunnel).

## Uninstalling

1. Run `php artisan pwa:disable --force` and leave it on until your users come back (their devices
   unregister the service worker).
2. Remove the components from your layout and the middleware from your routes.
3. Run `composer remove nyoncode/laravel-pwa`.

Without step 1, the old service worker stays in browsers until the user deletes it.
