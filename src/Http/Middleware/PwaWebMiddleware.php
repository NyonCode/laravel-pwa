<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Pwa;
use Symfony\Component\HttpFoundation\Response;

/**
 * Added to the `web` group:
 *  - remembers that the session runs in the installed app (?source=pwa),
 *  - sends X-PWA-User (a hash) to separate caches in the service worker,
 *  - after logout, sends X-PWA-Logout and Clear-Site-Data: "cache".
 */
class PwaWebMiddleware
{
    public const LOGOUT_ATTRIBUTE = 'pwa.logout';

    public function __construct(
        protected Pwa $pwa,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query('source') === 'pwa' && $request->hasSession()) {
            $request->session()->put('pwa.standalone', true);
        }

        $response = $next($request);

        if ($request->attributes->get(self::LOGOUT_ATTRIBUTE) === true) {
            $response->headers->set('X-PWA-Logout', '1');
            // Only "cache": "storage" would unregister the SW and cancel the push subscription.
            $response->headers->set('Clear-Site-Data', '"cache"');
            $response->headers->set('X-PWA-User', 'guest');

            return $response;
        }

        if (! $response->headers->has('X-PWA-User')) {
            $response->headers->set('X-PWA-User', $this->pwa->userHash($request));
        }

        return $response;
    }
}
