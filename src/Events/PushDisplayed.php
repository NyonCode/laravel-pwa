<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The worker showed a notification (pwa.push.analytics). Reported by the worker without
 * cookies: there is no user, only the message id(), its tag and the user agent.
 */
final readonly class PushDisplayed
{
    use Dispatchable;

    public function __construct(
        public ?string $id = null,
        public ?string $tag = null,
        public ?string $userAgent = null,
    ) {}
}
