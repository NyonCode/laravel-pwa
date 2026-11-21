<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NyonCode\LaravelPwa\Push\PushSubscription;

/**
 * A browser subscribed (or renewed its subscription) through POST /pwa/push/subscriptions.
 */
final readonly class PushSubscribed
{
    use Dispatchable;

    public function __construct(
        public PushSubscription $subscription,
    ) {}
}
