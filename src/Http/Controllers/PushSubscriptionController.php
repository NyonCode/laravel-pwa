<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NyonCode\LaravelPwa\Http\Requests\PushSubscriptionRequest;
use NyonCode\LaravelPwa\Push\PushSubscription;
use NyonCode\LaravelPwa\Push\WebPush;
use NyonCode\LaravelPwa\Pwa;
use NyonCode\LaravelPwa\Support\Value;

class PushSubscriptionController
{
    /** Session key with the endpoint hashes subscribed in this session (removed on logout). */
    public const SESSION_KEY = 'pwa.push.endpoints';

    public function store(PushSubscriptionRequest $request, WebPush $push, Pwa $pwa): JsonResponse
    {
        $owner = $request->owner();
        $replaces = Value::string($request->input('replaces'));

        // A renewal from the worker (pushsubscriptionchange) only replaces this user's own
        // subscription: it cannot quietly subscribe someone else who signed in meanwhile.
        if ($replaces !== '' && ! $push->owns($owner, $replaces)) {
            return response()->json(['subscribed' => false], 404);
        }

        $subscription = $push->subscribe($owner, [
            'endpoint' => $request->endpoint(),
            'publicKey' => $request->string('keys.p256dh')->toString(),
            'authToken' => $request->string('keys.auth')->toString(),
            'contentEncoding' => Value::nullableString($request->input('contentEncoding')),
            'expiresAt' => $request->integer('expirationTime') ?: null,
            'userAgent' => $request->userAgent(),
        ]);

        if ($replaces !== '' && $replaces !== $request->endpoint()) {
            $push->unsubscribe($owner, $replaces);
            $this->forgetInSession($request, PushSubscription::hashEndpoint($replaces));
        }

        $this->rememberInSession($request, $subscription->endpoint_hash);

        return response()->json([
            'subscribed' => true,
            'user' => $pwa->userHash($request),
        ], $subscription->wasRecentlyCreated ? 201 : 200, ['Cache-Control' => 'no-store']);
    }

    public function destroy(PushSubscriptionRequest $request, WebPush $push): Response
    {
        $push->unsubscribe($request->owner(), $request->endpoint());
        $this->forgetInSession($request, PushSubscription::hashEndpoint($request->endpoint()));

        return response()->noContent();
    }

    protected function rememberInSession(Request $request, string $hash): void
    {
        if ($request->hasSession()) {
            $hashes = array_map(Value::string(...), (array) $request->session()->get(self::SESSION_KEY, []));
            $request->session()->put(self::SESSION_KEY, array_values(array_unique([...$hashes, $hash])));
        }
    }

    protected function forgetInSession(Request $request, string $hash): void
    {
        if ($request->hasSession()) {
            $hashes = array_map(Value::string(...), (array) $request->session()->get(self::SESSION_KEY, []));
            $request->session()->put(self::SESSION_KEY, array_values(array_diff($hashes, [$hash])));
        }
    }
}
