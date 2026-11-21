<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use NyonCode\LaravelPwa\Http\Controllers\PushSubscriptionController;
use NyonCode\LaravelPwa\Push\WebPush;
use NyonCode\LaravelPwa\Support\Value;

/**
 * Logout removes the subscriptions made in this session: the next person on the device does
 * not receive the previous user's notifications. The client also drops the subscription in
 * the browser (pwa:logout).
 *
 * @internal
 */
class ForgetPushSubscriptions
{
    public function __construct(
        protected WebPush $push,
    ) {}

    public function handle(Logout $event): void
    {
        if (! $this->push->enabled() || ! $event->user instanceof Model || ! app()->bound('request')) {
            return;
        }

        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $hashes = array_map(Value::string(...), (array) $request->session()->pull(PushSubscriptionController::SESSION_KEY, []));

        $this->push->forget($event->user, array_values($hashes));
    }
}
