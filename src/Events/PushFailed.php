<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NyonCode\LaravelPwa\Push\PushReport;
use NyonCode\LaravelPwa\Push\PushSubscription;

/**
 * Delivery failed for another reason (network, 413 payload too large, 429, 5xx, an endpoint
 * outside pwa.push.endpoints). The subscription is kept.
 */
final readonly class PushFailed
{
    use Dispatchable;

    public function __construct(
        public PushSubscription $subscription,
        public PushReport $report,
    ) {}
}
