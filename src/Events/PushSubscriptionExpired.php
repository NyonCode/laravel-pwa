<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NyonCode\LaravelPwa\Push\PushReport;
use NyonCode\LaravelPwa\Push\PushSubscription;

/**
 * The push service answered 404 or 410: the subscription is gone and was deleted.
 * The model is no longer in the database.
 */
final readonly class PushSubscriptionExpired
{
    use Dispatchable;

    public function __construct(
        public PushSubscription $subscription,
        public PushReport $report,
    ) {}
}
