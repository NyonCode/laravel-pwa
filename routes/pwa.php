<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use NyonCode\LaravelPwa\Http\Controllers\AssetController;
use NyonCode\LaravelPwa\Http\Controllers\PushSubscriptionController;
use NyonCode\LaravelPwa\Http\Controllers\SessionController;

$prefix = trim((string) config('pwa.route_prefix', 'pwa'), '/');

// No session or cookies: the same for everyone, cacheable.
Route::get('manifest.webmanifest', [AssetController::class, 'manifest'])->name('pwa.manifest');
Route::get(ltrim((string) config('pwa.sw.url', '/sw.js'), '/'), [AssetController::class, 'serviceWorker'])->name('pwa.sw');
Route::get($prefix.'/client.js', [AssetController::class, 'client'])->name('pwa.client');
Route::get($prefix.'/ping', [AssetController::class, 'ping'])->name('pwa.ping');

Route::middleware('web')->group(function () use ($prefix): void {
    Route::get($prefix.'/session', [SessionController::class, 'session'])->name('pwa.session');

    Route::post($prefix.'/telemetry', [SessionController::class, 'telemetry'])
        ->middleware('throttle:30,1')
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('pwa.telemetry');

    // Optional web push module (pwa.push.enabled): the signed-in user's devices.
    if (filter_var(config('pwa.push.enabled', false), FILTER_VALIDATE_BOOL)) {
        Route::middleware([...(array) config('pwa.push.middleware', ['auth']), 'throttle:20,1'])->group(function () use ($prefix): void {
            Route::post($prefix.'/push/subscriptions', [PushSubscriptionController::class, 'store'])->name('pwa.push.subscribe');
            Route::delete($prefix.'/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('pwa.push.unsubscribe');
        });
    }

    if (config('pwa.offline.enabled', true)) {
        Route::get(ltrim((string) config('pwa.offline.route', '/offline'), '/'), [SessionController::class, 'offline'])->name('pwa.offline');
    }
});
