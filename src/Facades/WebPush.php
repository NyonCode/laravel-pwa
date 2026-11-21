<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use NyonCode\LaravelPwa\Push\PushReport;
use NyonCode\LaravelPwa\Push\PushSubscription;
use NyonCode\LaravelPwa\Push\WebPush as Service;
use NyonCode\LaravelPwa\Push\WebPushMessage;
use NyonCode\LaravelPwa\Testing\WebPushFake;

/**
 * @method static list<PushReport> send(object|iterable<PushSubscription> $to, WebPushMessage $message)
 * @method static list<PushReport> sendToSubscriptions(iterable<PushSubscription> $subscriptions, WebPushMessage $message, ?object $notifiable = null)
 * @method static iterable<PushSubscription> subscriptionsOf(object $notifiable)
 * @method static PushSubscription subscribe(Model $owner, array{endpoint: string, publicKey: string, authToken: string, contentEncoding?: string|null, expiresAt?: int|null, userAgent?: string|null} $data)
 * @method static bool unsubscribe(Model $owner, string $endpoint)
 * @method static bool allows(string $endpoint)
 * @method static string|null publicKey()
 * @method static bool enabled()
 * @method static void assertSentTo(object $notifiable, ?\Closure(WebPushMessage): bool $callback = null)
 * @method static void assertNotSentTo(object $notifiable, ?\Closure(WebPushMessage): bool $callback = null)
 * @method static void assertSent(?\Closure(WebPushMessage): bool $callback = null)
 * @method static void assertSentCount(int $count)
 * @method static void assertNothingSent()
 * @method static list<WebPushMessage> sent(?object $notifiable = null)
 *
 * @see Service
 * @see WebPushFake
 */
class WebPush extends Facade
{
    /**
     * Records messages instead of sending them; the channel and the service use the fake too.
     */
    public static function fake(): WebPushFake
    {
        $fake = static::getFacadeApplication()?->make(WebPushFake::class) ?? app(WebPushFake::class);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Service::class;
    }
}
