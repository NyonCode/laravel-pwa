---
title: Recipes
description: "Step-by-step setup in the Laravel starter kits (React, Vue, Livewire), with Jetstream or Fortify, and for a Filament panel."
---

# Recipes

Each recipe assumes the package is installed (`composer require nyoncode/laravel-pwa`,
`php artisan pwa:install`, icons from `pwa:icons`) and only shows what differs per stack.

## Laravel starter kits

### React and Vue (Inertia)

Add the head component to the Inertia root view:

```blade
{{-- resources/views/app.blade.php --}}
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <x-pwa::head/>
    @viteReactRefresh {{-- React only --}}
    @vite(['resources/js/app.tsx'])
    @inertiaHead
</head>
```

The Inertia login form sends `remember: false` unless the user ticks the box, so let the installed app
default to `true`:

```ts
// resources/js/pages/auth/login.tsx (Vue: login.vue)
const form = useForm({ email: '', password: '', remember: window.Pwa?.isInstalled() ?? false })
```

Allow offline copies of the pages you want with `pwa.cache` (kits keep their routes in `routes/web.php`
and `routes/settings.php`):

```php
Route::middleware(['auth', 'verified', 'pwa.cache'])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
});
```

The Inertia adapter handles 419, 503 and offline errors on its own. For install, update and push
buttons in React or Vue components, use the `usePwa` hook from [Blade components](components.md#vue-and-react).

### Livewire

Put `<x-pwa::head/>` in the shared `<head>` (in the Livewire kit, `resources/views/partials/head.blade.php`).
The login is a Livewire component, so its form posts to `/livewire/update`, not to a login route:
set the default of `remember` instead of using the `pwa.remember` middleware.

```php
use NyonCode\LaravelPwa\Facades\Pwa;

public bool $remember = false;

public function mount(): void
{
    $this->remember = Pwa::isStandaloneClient(); // ticked in the installed app
}
```

`wire:navigate` works as is: the client shows install, update and push elements again after each
navigation.

## Jetstream and Fortify

Fortify registers the login route itself, with the middleware from `config/fortify.php`. Add
`pwa.remember` there; on other Fortify routes it only adds a `remember` field nobody reads:

```php
// config/fortify.php
'middleware' => ['web', 'pwa.remember'],
```

Fortify's logout goes through the `web` group, so the package marks it (`X-PWA-Logout`) and the
worker deletes that user's caches. Two-factor challenges and password confirmations stay online-only:
they are POST navigations, which the worker never caches or queues.

## Filament

Render the head component in the panel and make the panel the app's scope:

```php
// app/Providers/Filament/AdminPanelProvider.php
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

return $panel
    ->id('admin')
    ->path('admin')
    ->spa()                                   // optional, the Livewire adapter supports it
    ->middleware(['pwa.cache'])
    ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render('<x-pwa::head/>'));
```

```php
// config/pwa.php
'id' => '/admin/',
'start_url' => '/admin?source=pwa',
'scope' => '/admin/',
```

`pwa.cache` lets the worker keep copies of the panel pages for each user; actions and table updates
go to `/livewire/update`, which is never cached, so they need a connection. To keep the installed
panel signed in, tick "remember me" by default in a custom login page (`->login(Login::class)`), in
the same way as the Livewire recipe: fill the form's `remember` field with
`Pwa::isStandaloneClient()` after the parent's `mount()`.

More panels on one domain (`/admin`, `/app`) are path tenants: see [Multi-tenant apps](tenancy.md).
