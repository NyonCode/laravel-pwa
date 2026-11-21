<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Controllers;

use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use NyonCode\LaravelPwa\Events\TelemetryReceived;
use NyonCode\LaravelPwa\Pwa;
use NyonCode\LaravelPwa\Support\Value;

class SessionController
{
    /**
     * Keep-alive: runs through the web middleware, so it extends the session. Returns a fresh
     * CSRF token for forms and fetch after returning from the background.
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
            'lifetime' => config()->integer('session.lifetime', 120),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function offline(): ViewContract
    {
        return View::make('pwa::offline');
    }

    /**
     * Errors from the service worker and the client. No personal data, only technical details.
     */
    public function telemetry(Request $request): JsonResponse
    {
        $max = config()->integer('pwa.telemetry.max_events', 20);

        $request->validate([
            'source' => ['required', 'in:sw,client'],
            'events' => ['required', 'array', 'max:'.$max],
            'events.*.type' => ['required', 'string', 'max:50'],
            'events.*.message' => ['required', 'string', 'max:500'],
            'events.*.stack' => ['nullable', 'string', 'max:2000'],
            'events.*.version' => ['nullable', 'string', 'max:40'],
            'events.*.display' => ['nullable', 'string', 'max:40'],
        ]);

        /** @var array<array-key, array<string, mixed>> $input */
        $input = $request->input('events');

        $events = array_map(static fn (array $event): array => [
            'type' => Value::string($event['type'] ?? null),
            'message' => Value::string($event['message'] ?? null),
            'stack' => Value::nullableString($event['stack'] ?? null),
            'version' => Value::nullableString($event['version'] ?? null),
            'display' => Value::nullableString($event['display'] ?? null),
        ], array_values($input));

        TelemetryReceived::dispatch(
            $request->input('source') === 'client' ? 'client' : 'sw',
            $events,
            $request->userAgent() !== null ? substr($request->userAgent(), 0, 300) : null,
        );

        return response()->json(['logged' => count($events)], 202);
    }
}
