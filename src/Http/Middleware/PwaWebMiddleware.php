<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Pwa;
use Symfony\Component\HttpFoundation\Response;

/**
 * Přidaný do skupiny `web`:
 *  - zapamatuje si, že session běží v nainstalované aplikaci (?source=pwa),
 *  - posílá X-PWA-User (hash) pro oddělení cache ve service workeru,
 *  - po odhlášení pošle X-PWA-Logout a Clear-Site-Data: "cache".
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
            // Jen "cache": "storage" by odregistroval SW a zrušil push odběr.
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
