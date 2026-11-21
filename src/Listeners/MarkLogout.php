<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Listeners;

use Illuminate\Auth\Events\Logout;
use NyonCode\LaravelPwa\Http\Middleware\PwaWebMiddleware;

/**
 * Po odhlášení označí požadavek; PwaWebMiddleware pak service workeru
 * pošle X-PWA-Logout a ten smaže cache a frontu tohoto uživatele.
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
