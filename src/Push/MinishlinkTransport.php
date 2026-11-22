<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Push;

use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as Client;
use NyonCode\LaravelPwa\Contracts\PushTransport;
use NyonCode\LaravelPwa\Support\Value;
use Psr\Http\Client\ClientInterface;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Delivers through minishlink/web-push (^10 or ^11): encryption (aes128gcm, aesgcm) and VAPID.
 *
 * @internal
 */
class MinishlinkTransport implements PushTransport
{
    /**
     * @param  array{subject: string, publicKey: string, privateKey: string}  $vapid
     * @param  ClientInterface|null  $http  PSR-18 client (minishlink/web-push 11+); null = discovered
     */
    public function __construct(
        protected array $vapid,
        protected ?ClientInterface $http = null,
    ) {}

    public static function available(): bool
    {
        return class_exists(Client::class);
    }

    public function send(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        $client = $this->client();
        $byEndpoint = [];

        foreach ($messages as $message) {
            $subscription = $message['subscription'];
            $byEndpoint[$subscription->endpoint] = true;

            $client->queueNotification(new Subscription(
                $subscription->endpoint,
                $subscription->public_key,
                $subscription->auth_token,
                $subscription->content_encoding,
            ), $message['payload'], $message['options']);
        }

        $reports = [];

        foreach ($client->flush(max(1, Value::configInt('pwa.push.batch_size', 1000))) as $report) {
            /** @var MessageSentReport $report */
            $reports[$report->getEndpoint()] = new PushReport(
                endpoint: $report->getEndpoint(),
                success: $report->isSuccess(),
                status: $report->getResponse()?->getStatusCode(),
                reason: $report->isSuccess() ? '' : $report->getReason(),
            );
        }

        return array_map(
            static fn (array $message): PushReport => $reports[$message['subscription']->endpoint]
                ?? new PushReport($message['subscription']->endpoint, false, null, 'No report from the push library'),
            $messages,
        );
    }

    protected function client(): Client
    {
        if (! self::available()) {
            throw new RuntimeException('Web push needs minishlink/web-push: composer require minishlink/web-push');
        }

        $auth = ['VAPID' => $this->vapid];

        return $this->http instanceof ClientInterface && $this->acceptsPsrClient()
            ? new Client($auth, [], $this->http)
            : new Client($auth);
    }

    /** Version 11 takes a PSR-18 client as the third argument, version 10 a timeout. */
    protected function acceptsPsrClient(): bool
    {
        $type = (new ReflectionParameter([Client::class, '__construct'], 2))->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === ClientInterface::class;
    }
}
