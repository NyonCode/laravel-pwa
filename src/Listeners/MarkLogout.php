<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Listeners;

use Illuminate\Auth\Events\Logout;
use NyonCode\LaravelPwa\Http\Middleware\PwaWebMiddleware;

/**
 * Marks the request after logout; PwaWebMiddleware then sends the service worker
 * X-PWA-Logout, which clears this user's cache and queue.
 */
class MarkLogout
{
    public function handle(Logout $event): void
    {
        if (app()->bound('request')) {
            request()->attributes->set(PwaWebMiddleware::LOGOUT_ATTRIBUTE, true);
        }
    }
}
