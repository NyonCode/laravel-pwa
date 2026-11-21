<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NyonCode\LaravelPwa\Push\PushSubscription;

/**
 * A subscription was removed: DELETE /pwa/push/subscriptions or the logout of the user who subscribed on this device.
 */
final readonly class PushUnsubscribed
{
    use Dispatchable;

    public function __construct(
        public PushSubscription $subscription,
    ) {}
}
