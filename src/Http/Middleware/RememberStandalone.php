<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Pwa;
use Symfony\Component\HttpFoundation\Response;

/**
 * Na přihlašovací routě: v nainstalované aplikaci vždy zapne „zapamatovat mě“.
 *
 *     Route::post('/login', ...)->middleware('pwa.remember');
 *
 * Laravel pak nastaví HttpOnly remember cookie ze serveru, kterou ITP na iOS
 * nemaže po 7 dnech (na rozdíl od úložiště zapsaného skriptem).
 */
class RememberStandalone
{
    public function __construct(
        protected Pwa $pwa,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('pwa.session.standalone_remember', true) && $this->pwa->isStandaloneRequest($request)) {
            $request->merge([(string) config('pwa.session.remember_field', 'remember') => 'on']);
        }

        return $next($request);
    }
}
