<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Push;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use NyonCode\LaravelPwa\Contracts\PushTransport;
use NyonCode\LaravelPwa\Events\PushFailed;
use NyonCode\LaravelPwa\Events\PushSubscribed;
use NyonCode\LaravelPwa\Events\PushSubscriptionExpired;
use NyonCode\LaravelPwa\Events\PushUnsubscribed;
use NyonCode\LaravelPwa\Pwa;
use NyonCode\LaravelPwa\Support\Value;
use RuntimeException;

/**
 * Stores subscriptions and sends messages to them. WebPushChannel uses it for notifications;
 * call it directly for one-off messages:
 *
 *     WebPush::send($user, WebPushMessage::create('Backup finished'));
 */
class WebPush
{
    public function __construct(
        protected Application $app,
    ) {}

    public function enabled(): bool
    {
        return filter_var(config('pwa.push.enabled', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return class-string<PushSubscription>
     */
    public static function subscriptionModel(): string
    {
        $model = config('pwa.push.model', PushSubscription::class);

        if (! is_string($model) || ! is_a($model, PushSubscription::class, true)) {
            throw new InvalidArgumentException('pwa.push.model must be '.PushSubscription::class.' or extend it.');
        }

        return $model;
    }

    /**
     * Sends to every subscription of a notifiable (routeNotificationForWebPush()), one
     * subscription or a list of them.
     *
     * @param  object|iterable<mixed>  $to
     * @return list<PushReport>
     */
    public function send(object|iterable $to, WebPushMessage $message): array
    {
        if ($to instanceof PushSubscription) {
            return $this->sendToSubscriptions([$to], $message, $to->subscribable);
        }

        if (is_iterable($to)) {
            return $this->sendToSubscriptions($this->only($to), $message);
        }

        return $this->sendToSubscriptions($this->subscriptionsOf($to), $message, $to);
    }

    /**
     * @param  iterable<PushSubscription>  $subscriptions
     * @param  object|null  $notifiable  for the per-user check in the worker
     * @return list<PushReport>
     */
    public function sendToSubscriptions(iterable $subscriptions, WebPushMessage $message, ?object $notifiable = null): array
    {
        $payload = $message->toJson($this->recipientHash($notifiable));
        $options = $message->deliveryOptions();
        $messages = [];
        $reports = [];

        foreach ($subscriptions as $subscription) {
            if (! $this->allows($subscription->endpoint)) {
                $report = new PushReport($subscription->endpoint, false, null, 'Endpoint host is not in pwa.push.endpoints');
                PushFailed::dispatch($subscription, $report);
                $reports[] = $report;

                continue;
            }

            $messages[] = ['subscription' => $subscription, 'payload' => $payload, 'options' => $options];
        }

        if ($messages === []) {
            return $reports;
        }

        foreach ($this->transport()->send($messages) as $index => $report) {
            $this->settle($messages[$index]['subscription'], $report);
            $reports[] = $report;
        }

        return $reports;
    }

    /**
     * @return iterable<PushSubscription>
     */
    public function subscriptionsOf(object $notifiable): iterable
    {
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $route = $notifiable->routeNotificationFor('webPush');
        } elseif (method_exists($notifiable, 'routeNotificationForWebPush')) {
            $route = $notifiable->routeNotificationForWebPush();
        } else {
            $route = null;
        }

        if ($route instanceof PushSubscription) {
            return [$route];
        }

        return is_iterable($route) ? $this->only($route) : [];
    }

    /**
     * @param  iterable<mixed>  $items
     * @return list<PushSubscription>
     */
    protected function only(iterable $items): array
    {
        $subscriptions = [];

        foreach ($items as $item) {
            if ($item instanceof PushSubscription) {
                $subscriptions[] = $item;
            }
        }

        return $subscriptions;
    }

    /**
     * Stores the browser's subscription for $owner. The same endpoint signed in as another
     * owner moves to $owner (one device, one subscription). Keeps at most
     * pwa.push.max_subscriptions per owner.
     *
     * @param  array{endpoint: string, publicKey: string, authToken: string, contentEncoding?: string|null, expiresAt?: int|null, userAgent?: string|null}  $data
     */
    public function subscribe(Model $owner, array $data): PushSubscription
    {
        $model = self::subscriptionModel();
        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        $expires = $data['expiresAt'] ?? null;

        $subscription = $model::query()->firstOrNew(['endpoint_hash' => $hash]);
        $subscription->fill([
            'endpoint' => $data['endpoint'],
            'public_key' => $data['publicKey'],
            'auth_token' => $data['authToken'],
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            'user_agent' => isset($data['userAgent']) ? Str::limit($data['userAgent'], 297) : null,
            'expires_at' => $expires !== null ? now()->setTimestamp(intdiv($expires, 1000)) : null,
            'last_used_at' => now(),
        ]);
        $subscription->subscribable()->associate($owner);
        $subscription->save();

        $this->trim($owner);

        PushSubscribed::dispatch($subscription);

        return $subscription;
    }

    /**
     * Removes $owner's subscription of this endpoint. Returns false when $owner has none.
     */
    public function unsubscribe(Model $owner, string $endpoint): bool
    {
        return $this->forget($owner, [PushSubscription::hashEndpoint($endpoint)]) > 0;
    }

    /**
     * Removes $owner's subscriptions with these endpoint hashes.
     *
     * @param  list<string>  $hashes
     */
    public function forget(Model $owner, array $hashes): int
    {
        if ($hashes === []) {
            return 0;
        }

        $subscriptions = $this->ownedBy($owner)->whereIn('endpoint_hash', $hashes)->get();

        foreach ($subscriptions as $subscription) {
            $subscription->delete();
            PushUnsubscribed::dispatch($subscription);
        }

        return $subscriptions->count();
    }

    public function owns(Model $owner, string $endpoint): bool
    {
        return $this->ownedBy($owner)->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->exists();
    }

    /**
     * Is the endpoint an https URL of an allowed push service (pwa.push.endpoints)?
     * The server POSTs to it, so this keeps users from pointing it at other hosts.
     */
    public function allows(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        $allowed = config('pwa.push.endpoints');

        if ($allowed === null) {
            return true;
        }

        $patterns = array_map(Value::string(...), (array) $allowed);

        return Str::is($patterns, strtolower($parts['host']));
    }

    /**
     * The public VAPID key the browser subscribes with (applicationServerKey).
     */
    public function publicKey(): ?string
    {
        $key = config('pwa.push.vapid.public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function transport(): PushTransport
    {
        return $this->app->make(PushTransport::class);
    }

    /**
     * The default transport, built from pwa.push.vapid.
     */
    public static function defaultTransport(): PushTransport
    {
        $vapid = (array) config('pwa.push.vapid', []);
        $problem = Vapid::problem($vapid['public_key'] ?? null, $vapid['private_key'] ?? null, $vapid['subject'] ?? null);

        if ($problem !== null) {
            throw new RuntimeException("Web push: {$problem}.");
        }

        return new MinishlinkTransport([
            'subject' => Value::string($vapid['subject']),
            'publicKey' => Value::string($vapid['public_key']),
            'privateKey' => Value::string($vapid['private_key']),
        ]);
    }

    protected function settle(PushSubscription $subscription, PushReport $report): void
    {
        if ($report->success) {
            $subscription->forceFill(['last_used_at' => now()])->save();

            return;
        }

        if ($report->expired()) {
            $subscription->delete();
            PushSubscriptionExpired::dispatch($subscription, $report);

            return;
        }

        PushFailed::dispatch($subscription, $report);
    }

    /**
     * The recipient's identity hash, so a device where someone else is signed in hides the content.
     */
    protected function recipientHash(?object $notifiable): ?string
    {
        return $notifiable instanceof Authenticatable
            ? $this->app->make(Pwa::class)->identityHash($notifiable->getAuthIdentifier())
            : null;
    }

    /**
     * @return Builder<PushSubscription>
     */
    protected function ownedBy(Model $owner): Builder
    {
        return self::subscriptionModel()::query()
            ->where('subscribable_type', $owner->getMorphClass())
            ->where('subscribable_id', $owner->getKey());
    }

    protected function trim(Model $owner): void
    {
        $max = max(1, config()->integer('pwa.push.max_subscriptions', 10));

        $keep = $this->ownedBy($owner)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->take($max)
            ->pluck('id');

        $stale = $this->ownedBy($owner)->whereNotIn('id', $keep)->get();

        foreach ($stale as $subscription) {
            $subscription->delete();
            PushUnsubscribed::dispatch($subscription);
        }
    }
}
