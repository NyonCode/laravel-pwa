<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Pwa;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the login route: turns on "remember me" in the installed app.
 *
 *     Route::post('/login', ...)->middleware('pwa.remember');
 *
 * Laravel then sets an HttpOnly remember cookie from the server, which ITP on iOS
 * does not delete after 7 days (unlike storage written by a script).
 *
 * Only the client signal (header / cookie) decides, not ?source=pwa or the session,
 * which a link can plant. A field explicitly sent as false is not overwritten.
 */
class RememberStandalone
{
    public function __construct(
        protected Pwa $pwa,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $field = config()->string('pwa.session.remember_field', 'remember');

        if (config('pwa.session.standalone_remember', true)
            && $this->pwa->isStandaloneClient($request)
            && (! $request->exists($field) || $request->boolean($field))) {
            $request->merge([$field => 'on']);
        }

        return $next($request);
    }
}
