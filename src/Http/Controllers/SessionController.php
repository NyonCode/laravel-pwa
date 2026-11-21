<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Controllers;

use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use NyonCode\LaravelPwa\Pwa;

class SessionController
{
    /**
     * Keep-alive: projde web middleware, takže prodlouží session. Vrací čerstvý
     * CSRF token pro formuláře a fetch po návratu z pozadí.
     */
    public function session(Request $request, Pwa $pwa): JsonResponse
    {
        if ($pwa->isStandaloneRequest($request) && $request->hasSession()) {
            $request->session()->put('pwa.standalone', true);
        }

        return response()->json([
            'authenticated' => $request->user() !== null,
            'user' => $pwa->userHash($request),
            'csrf' => csrf_token(),
            'lifetime' => (int) config('session.lifetime', 120),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function offline(): ViewContract
    {
        return View::make('pwa::offline');
    }

    /**
     * Chyby ze service workeru a klienta. Bez osobních údajů, jen technická data.
     */
    public function telemetry(Request $request): JsonResponse
    {
        $max = (int) config('pwa.telemetry.max_events', 20);

        $data = $request->validate([
            'source' => ['required', 'in:sw,client'],
            'events' => ['required', 'array', 'max:'.$max],
            'events.*.type' => ['required', 'string', 'max:50'],
            'events.*.message' => ['required', 'string', 'max:500'],
            'events.*.stack' => ['nullable', 'string', 'max:2000'],
            'events.*.version' => ['nullable', 'string', 'max:40'],
            'events.*.display' => ['nullable', 'string', 'max:40'],
        ]);

        $channel = config('pwa.telemetry.channel');
        $logger = is_string($channel) && config("logging.channels.{$channel}") !== null
            ? Log::channel($channel)
            : Log::getFacadeRoot();

        foreach ($data['events'] as $event) {
            $logger->warning('[pwa] '.$event['type'].': '.$event['message'], [
                'pwa' => true,
                'source' => $data['source'],
                'version' => $event['version'] ?? null,
                'display' => $event['display'] ?? null,
                'stack' => $event['stack'] ?? null,
                'user_agent' => substr((string) $request->userAgent(), 0, 300),
            ]);
        }

        return response()->json(['logged' => count($data['events'])], 202);
    }
}
