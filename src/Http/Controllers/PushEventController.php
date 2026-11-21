<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NyonCode\LaravelPwa\Events\PushClicked;
use NyonCode\LaravelPwa\Events\PushDismissed;
use NyonCode\LaravelPwa\Events\PushDisplayed;
use NyonCode\LaravelPwa\Support\Value;

/**
 * Notification statistics from the worker (pwa.push.analytics): shown, clicked, closed.
 * Sent without cookies, so nothing here identifies the user.
 *
 * @internal
 */
final class PushEventController
{
    public function __invoke(Request $request): Response
    {
        $request->validate([
            'events' => ['required', 'array', 'max:20'],
            'events.*.type' => ['required', 'in:shown,click,close'],
            'events.*.id' => ['nullable', 'string', 'max:100'],
            'events.*.tag' => ['nullable', 'string', 'max:100'],
            'events.*.action' => ['nullable', 'string', 'max:100'],
        ]);

        $userAgent = $request->userAgent() !== null ? substr($request->userAgent(), 0, 300) : null;

        /** @var list<array<string, mixed>> $events */
        $events = array_values((array) $request->input('events'));

        foreach ($events as $event) {
            $id = Value::nullableString($event['id'] ?? null);
            $tag = Value::nullableString($event['tag'] ?? null);

            match ($event['type'] ?? null) {
                'shown' => PushDisplayed::dispatch($id, $tag, $userAgent),
                'click' => PushClicked::dispatch($id, $tag, Value::nullableString($event['action'] ?? null), $userAgent),
                default => PushDismissed::dispatch($id, $tag, $userAgent),
            };
        }

        return response()->noContent(202);
    }
}
