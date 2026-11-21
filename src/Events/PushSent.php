<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NyonCode\LaravelPwa\Push\PushReport;
use NyonCode\LaravelPwa\Push\WebPushMessage;

/**
 * One message went out to a notifiable's devices (or a list of subscriptions): a summary of the
 * delivery reports. Expired and failed subscriptions also get their own events.
 */
final readonly class PushSent
{
    use Dispatchable;

    /**
     * @param  list<PushReport>  $reports
     */
    public function __construct(
        public WebPushMessage $message,
        public array $reports,
        public ?object $notifiable = null,
    ) {}

    public function delivered(): int
    {
        return count(array_filter($this->reports, static fn (PushReport $report): bool => $report->success));
    }

    public function expired(): int
    {
        return count(array_filter($this->reports, static fn (PushReport $report): bool => $report->expired()));
    }

    public function failed(): int
    {
        return count($this->reports) - $this->delivered() - $this->expired();
    }
}
