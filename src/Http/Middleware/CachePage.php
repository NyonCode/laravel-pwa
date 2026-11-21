<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Povolí service workeru uložit stránku do cache pro offline režim.
 *
 *     Route::get('/dashboard', ...)->middleware('pwa.cache');
 *
 * Bez této hlavičky SW žádné HTML neukládá. Cache je oddělená pro každého
 * uživatele a maže se při odhlášení.
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
