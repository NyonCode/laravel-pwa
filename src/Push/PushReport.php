<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Push;

/**
 * The push service's answer for one subscription.
 */
final readonly class PushReport
{
    public function __construct(
        public string $endpoint,
        public bool $success,
        public ?int $status = null,
        public string $reason = '',
    ) {}

    /** 404 and 410: the browser dropped the subscription, it will never work again. */
    public function expired(): bool
    {
        return in_array($this->status, [404, 410], true);
    }
}
