<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Tests\Fixtures;

use NyonCode\LaravelPwa\Contracts\PushTransport;
use NyonCode\LaravelPwa\Push\PushReport;
use NyonCode\LaravelPwa\Push\PushSubscription;

class FakeTransport implements PushTransport
{
    /** @var list<array{subscription: PushSubscription, payload: string, options: array<string, int|string>}> */
    public array $sent = [];

    /** @var array<string, int> status per endpoint, default 201 */
    public array $statuses = [];

    public function send(array $messages): array
    {
        array_push($this->sent, ...$messages);

        return array_map(function (array $message): PushReport {
            $status = $this->statuses[$message['subscription']->endpoint] ?? 201;

            return new PushReport($message['subscription']->endpoint, $status < 300, $status, $status < 300 ? '' : "HTTP {$status}");
        }, $messages);
    }

    /** @return array<string, mixed> */
    public function payload(int $index = 0): array
    {
        return json_decode($this->sent[$index]['payload'], true, flags: JSON_THROW_ON_ERROR);
    }
}
