<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the service worker cache the page for offline use.
 *
 *     Route::get('/dashboard', ...)->middleware('pwa.cache');
 *
 * Without this header the SW caches no HTML. The cache is separate for each
 * user and is cleared on logout.
 */
class CachePage
{
    public const HEADER = 'X-PWA-Cache';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->getStatusCode() === 200) {
            $response->headers->set(self::HEADER, 'allow');
        }

        return $response;
    }
}
