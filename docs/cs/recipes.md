---
title: Recepty
description: "Nastavení krok za krokem ve startovacích sadách Laravelu (React, Vue, Livewire), s Jetstreamem nebo Fortify a pro panel Filament."
---

# Recepty

Každý recept předpokládá nainstalovaný balíček (`composer require nyoncode/laravel-pwa`,
`php artisan pwa:install`, ikony z `pwa:icons`) a ukazuje jen to, čím se daná sada liší.

## Startovací sady Laravelu

### React a Vue (Inertia)

Přidejte komponentu head do kořenového view Inertie:

```blade
{{-- resources/views/app.blade.php --}}
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <x-pwa::head/>
    @viteReactRefresh {{-- jen React --}}
    @vite(['resources/js/app.tsx'])
    @inertiaHead
</head>
```

Přihlašovací formulář Inertie posílá `remember: false`, dokud uživatel nezaškrtne políčko, proto ať má
nainstalovaná aplikace výchozí `true`:

```ts
// resources/js/pages/auth/login.tsx (Vue: login.vue)
const form = useForm({ email: '', password: '', remember: window.Pwa?.isInstalled() ?? false })
```

Offline kopie povolte stránkám, které chcete, pomocí `pwa.cache` (sady mají routy v `routes/web.php`
a `routes/settings.php`):

```php
Route::middleware(['auth', 'verified', 'pwa.cache'])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
});
```

Adaptér pro Inertii sám vyřeší chyby 419, 503 i offline. Pro tlačítka instalace, aktualizace a push
v komponentách Reactu nebo Vue použijte hook `usePwa` z [Blade komponent](components.md#vue-a-react).

### Livewire

Vložte `<x-pwa::head/>` do sdíleného `<head>` (v sadě Livewire `resources/views/partials/head.blade.php`).
Přihlášení je komponenta Livewire, formulář tedy jde na `/livewire/update`, ne na routu přihlášení:
místo middleware `pwa.remember` nastavte výchozí hodnotu `remember`.

```php
use NyonCode\LaravelPwa\Facades\Pwa;

public bool $remember = false;

public function mount(): void
{
    $this->remember = Pwa::isStandaloneClient(); // zaškrtnuto v nainstalované aplikaci
}
```

`wire:navigate` funguje bez úprav: klient po každé navigaci znovu zobrazí prvky instalace,
aktualizace a push.

## Jetstream a Fortify

Fortify si routu přihlášení registruje sám, s middleware z `config/fortify.php`. Přidejte tam
`pwa.remember`; na ostatních routách Fortify jen přidá pole `remember`, které nikdo nečte:

```php
// config/fortify.php
'middleware' => ['web', 'pwa.remember'],
```

Odhlášení přes Fortify jde skupinou `web`, takže ho balíček označí (`X-PWA-Logout`) a worker smaže
cache daného uživatele. Dvoufázové ověření a potvrzení hesla zůstávají jen online: jsou to POST
navigace, které worker nikdy necachuje ani nezařazuje do fronty.

## Filament

Vykreslete komponentu head v panelu a nastavte panel jako scope aplikace:

```php
// app/Providers/Filament/AdminPanelProvider.php
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

return $panel
    ->id('admin')
    ->path('admin')
    ->spa()                                   // volitelně, adaptér Livewire ho podporuje
    ->middleware(['pwa.cache'])
    ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render('<x-pwa::head/>'));
```

```php
// config/pwa.php
'id' => '/admin/',
'start_url' => '/admin?source=pwa',
'scope' => '/admin/',
```

`pwa.cache` dovolí workeru uchovat kopie stránek panelu pro každého uživatele; akce a aktualizace
tabulek jdou na `/livewire/update`, který se nikdy necachuje, takže potřebují připojení. Aby
nainstalovaný panel zůstal přihlášený, zaškrtněte „zapamatovat si mě“ ve vlastní přihlašovací stránce
(`->login(Login::class)`) stejně jako v receptu pro Livewire: po `mount()` rodiče vyplňte pole
`remember` formuláře hodnotou `Pwa::isStandaloneClient()`.

Více panelů na jedné doméně (`/admin`, `/app`) jsou cestové tenanty: viz [Více tenantů](tenancy.md).
