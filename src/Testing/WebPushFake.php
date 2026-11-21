<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use NyonCode\LaravelPwa\Push\PushReport;
use NyonCode\LaravelPwa\Push\WebPush;
use NyonCode\LaravelPwa\Push\WebPushMessage;
use PHPUnit\Framework\Assert;

/**
 * WebPush::fake(): messages are built and checked (title, payload size) but never sent.
 *
 *     WebPush::fake();
 *     $user->notify(new OrderShipped($order));
 *     WebPush::assertSentTo($user, fn (WebPushMessage $message) => $message->getTitle() === 'Order shipped');
 */
class WebPushFake extends WebPush
{
    /** @var list<array{notifiable: object|null, message: WebPushMessage, subscriptions: int}> */
    protected array $sent = [];

    public function sendToSubscriptions(iterable $subscriptions, WebPushMessage $message, ?object $notifiable = null): array
    {
        $message->toJson();
        $reports = [];

        foreach ($subscriptions as $subscription) {
            $reports[] = new PushReport($subscription->endpoint, true, 201);
        }

        $this->sent[] = ['notifiable' => $notifiable, 'message' => $message, 'subscriptions' => count($reports)];

        return $reports;
    }

    /**
     * Messages sent, optionally only those to one notifiable.
     *
     * @return list<WebPushMessage>
     */
    public function sent(?object $notifiable = null): array
    {
        return array_values(array_map(
            static fn (array $entry): WebPushMessage => $entry['message'],
            array_filter($this->sent, fn (array $entry): bool => $notifiable === null || $this->same($entry['notifiable'], $notifiable)),
        ));
    }

    /**
     * @param  (Closure(WebPushMessage): bool)|null  $callback
     */
    public function assertSentTo(object $notifiable, ?Closure $callback = null): void
    {
        Assert::assertNotEmpty($this->matching($this->sent($notifiable), $callback), 'No matching web push was sent to '.$notifiable::class.'.');
    }

    /**
     * @param  (Closure(WebPushMessage): bool)|null  $callback
     */
    public function assertNotSentTo(object $notifiable, ?Closure $callback = null): void
    {
        Assert::assertEmpty($this->matching($this->sent($notifiable), $callback), 'An unexpected web push was sent to '.$notifiable::class.'.');
    }

    /**
     * @param  (Closure(WebPushMessage): bool)|null  $callback
     */
    public function assertSent(?Closure $callback = null): void
    {
        Assert::assertNotEmpty($this->matching($this->sent(), $callback), 'No matching web push was sent.');
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent, "Expected {$count} web push messages, ".count($this->sent).' sent.');
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }

    /**
     * @param  list<WebPushMessage>  $messages
     * @param  (Closure(WebPushMessage): bool)|null  $callback
     * @return list<WebPushMessage>
     */
    protected function matching(array $messages, ?Closure $callback): array
    {
        return $callback instanceof Closure ? array_values(array_filter($messages, $callback)) : $messages;
    }

    protected function same(?object $a, object $b): bool
    {
        if ($a instanceof Model && $b instanceof Model) {
            return $a->is($b);
        }

        return $a === $b;
    }
}
