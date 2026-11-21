---
title: Instalace
description: Instalace balíčku, layout, ikony, routy, výjimky z údržby a nasazení krok za krokem.
---

# Instalace

Po těchto krocích je aplikace instalovatelná, funguje offline a `php artisan pwa:doctor` hlásí
zelenou.

## Požadavky

| Co | Verze |
| --- | --- |
| PHP | 8.2+ (Laravel 13 vyžaduje 8.3+) |
| Laravel | 12.x nebo 13.x |
| HTTPS | povinné v produkci; `localhost` má výjimku |
| Vite | doporučené, pro precache assetů |
| `ext-gd` nebo `ext-imagick` | jen pro `pwa:icons` (SVG vyžaduje imagick) |

## 1. Composer

```bash
composer require nyoncode/laravel-pwa
php artisan pwa:install
```

`pwa:install` publikuje `config/pwa.php` a vypíše další kroky. Provider se registruje sám
(package discovery).

## 2. Layout

Do `<head>` hlavního layoutu:

```blade
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<x-pwa::head/>
```

- `viewport-fit=cover` dovolí obsahu jít pod výřez displeje na iPhonu; okraje pak řešte přes
  `env(safe-area-inset-*)`.
- `csrf-token` není povinný, ale klient do něj propisuje obnovený token.

Volitelně do hlavičky aplikace:

```blade
<x-pwa::nav/>
<x-pwa::install class="btn btn-primary">Nainstalovat aplikaci</x-pwa::install>
```

Detaily v [Komponentách](components.md).

## 3. Ikony

```bash
php artisan pwa:icons resources/pwa/icon.png
```

Zdroj ideálně 1024×1024, čtvercový, s logem uprostřed. Vznikne `public/pwa/icons/` s ikonami
192 a 512 px, maskable ikonou pro Android, `apple-touch-icon.png` (180 px, s pozadím),
monochromatickou ikonou a faviconou. S `--splash` i úvodní obrazovky pro iOS.
Manifest uvádí jen ikony, které na disku existují.

## 4. Routy

```php
// routes/web.php

// Přihlášení: v nainstalované aplikaci vždy „zapamatovat mě“
Route::post('/login', [LoginController::class, 'store'])->middleware('pwa.remember');

// Stránky, které mají fungovat bez připojení
Route::middleware(['auth', 'pwa.cache'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/orders', [OrderController::class, 'index']);
});
```

S Breeze, Jetstream nebo Fortify přidejte `pwa.remember` na jejich login routu (u Fortify přes
`Fortify::authenticateThrough()` nebo vlastní routu). Viz [Trvalé přihlášení](session.md).

## 5. Výjimky z údržby

V `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->preventRequestsDuringMaintenance(except: [
        'manifest.webmanifest',
        'sw.js',
        'pwa/*',
    ]);
})
```

Bez nich vše funguje také, jen keep-alive a manifest během údržby vracejí 503.
Viz [Údržba](maintenance.md).

## 6. Nasazení

Za `npm run build` v deploy skriptu:

```bash
php artisan pwa:build
php artisan pwa:doctor --ci
```

`pwa:build` je napojený i na `php artisan optimize`. Viz [Nasazení](deployment.md).

## 7. Kontrola

```bash
php artisan pwa:doctor
```

Projde HTTPS, ikony, scope, build service workeru, výjimky z údržby, session a další. Chyby
opravte, varování zvažte.

## Lokální vývoj

Při `APP_ENV=local` je service worker ve výchozím stavu vypnutý, aby necachoval soubory z Vite
dev serveru. Pro test PWA lokálně:

```dotenv
PWA_ENABLED=true
```

a `npm run build` místo `npm run dev`. Na telefonu v síti potřebujete HTTPS (Herd, Valet, tunel).

## Odinstalace

1. `php artisan pwa:disable --force` a nechte ho běžet, dokud se uživatelé nevrátí (zařízení
   service worker odregistrují).
2. Odstraňte komponenty z layoutu a middleware z rout.
3. `composer remove nyoncode/laravel-pwa`.

Bez kroku 1 zůstane starý service worker v prohlížečích, dokud ho uživatel nesmaže.
