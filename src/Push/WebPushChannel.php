<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Push;

use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * Notification channel: `via()` returns [WebPushChannel::class] (or 'webpush') and the
 * notification implements toWebPush($notifiable): WebPushMessage. The notifiable uses
 * HasPushSubscriptions or defines routeNotificationForWebPush().
 */
class WebPushChannel
{
    public function __construct(
        protected WebPush $push,
    ) {}

    /**
     * @return list<PushReport>
     */
    public function send(object $notifiable, Notification $notification): array
    {
        if (! method_exists($notification, 'toWebPush')) {
            throw new InvalidArgumentException($notification::class.' must define toWebPush($notifiable) to be sent through '.self::class.'.');
        }

        $message = $notification->toWebPush($notifiable);

        if (! $message instanceof WebPushMessage) {
            throw new InvalidArgumentException($notification::class.'::toWebPush() must return '.WebPushMessage::class.'.');
        }

        $subscriptions = $this->push->subscriptionsOf($notifiable);

        return $this->push->sendToSubscriptions($subscriptions, $message, $notifiable);
    }
}
