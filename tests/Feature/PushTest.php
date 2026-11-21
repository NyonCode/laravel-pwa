<?php

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use NyonCode\LaravelPwa\Events\PushFailed;
use NyonCode\LaravelPwa\Events\PushSubscribed;
use NyonCode\LaravelPwa\Events\PushSubscriptionExpired;
use NyonCode\LaravelPwa\Events\PushUnsubscribed;
use NyonCode\LaravelPwa\Facades\Pwa;
use NyonCode\LaravelPwa\Facades\WebPush;
use NyonCode\LaravelPwa\Http\Controllers\PushSubscriptionController;
use NyonCode\LaravelPwa\Push\MinishlinkTransport;
use NyonCode\LaravelPwa\Push\PushSubscription;
use NyonCode\LaravelPwa\Push\WebPushChannel;
use NyonCode\LaravelPwa\Push\WebPushMessage;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use NyonCode\LaravelPwa\Tests\Fixtures\PushUser;
use NyonCode\LaravelPwa\Tests\Support\WithPush;
use PHPUnit\Framework\ExpectationFailedException;

uses(WithPush::class);

const FCM = 'https://fcm.googleapis.com/fcm/send/device-a';

/**
 * @return array<string, mixed>
 */
function browserSubscription(string $endpoint = FCM, array $extra = []): array
{
    return [
        'endpoint' => $endpoint,
        'expirationTime' => null,
        'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
        'contentEncoding' => 'aes128gcm',
        ...$extra,
    ];
}

function subscriptionFor(PushUser $user, string $endpoint = FCM): PushSubscription
{
    return app(NyonCode\LaravelPwa\Push\WebPush::class)->subscribe($user, [
        'endpoint' => $endpoint,
        'publicKey' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'authToken' => 'tBHItJI5svbpez7KI4CCXg',
    ]);
}

class OrderShipped extends Notification
{
    public function __construct(public string $via = WebPushChannel::class) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [$this->via];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return WebPushMessage::create('Order shipped')->body('Order 1001 is on its way.')->url('/orders/1001');
    }
}

describe('configuration', function (): void {
    it('passes the public key and the route to the client and the worker', function (): void {
        $client = Pwa::clientConfig()['push'];
        $worker = app(ServiceWorkerBuilder::class)->runtimeConfig()['push'];

        expect($client)->toBe(['key' => config('pwa.push.vapid.public_key'), 'url' => '/pwa/push/subscriptions'])
            ->and($worker)->toMatchArray([
                'key' => config('pwa.push.vapid.public_key'),
                'url' => '/pwa/push/subscriptions',
                'name' => 'Testovací aplikace',
                'icon' => null,
                'badge' => null,
                'fallback' => 'You have a new notification. Open the app to see it.',
            ]);
    });

    it('leaves the notifications to sw.import scripts when handle_in_worker is off', function (): void {
        config(['pwa.push.handle_in_worker' => false]);

        expect(app(ServiceWorkerBuilder::class)->runtimeConfig()['push'])->toBeNull()
            ->and(Pwa::clientConfig()['push'])->not->toBeNull();
    });

    it('is off without a public key', function (): void {
        config(['pwa.push.vapid.public_key' => null]);

        expect(Pwa::clientConfig()['push'])->toBeNull()
            ->and(app(ServiceWorkerBuilder::class)->runtimeConfig()['push'])->toBeNull();
    });
});

describe('configuration errors', function (): void {
    it('builds the minishlink transport from the VAPID config', function (): void {
        expect(NyonCode\LaravelPwa\Push\WebPush::defaultTransport())->toBeInstanceOf(MinishlinkTransport::class);
    });

    it('requires the subscription model to extend PushSubscription', function (): void {
        config(['pwa.push.model' => PushUser::class]);

        NyonCode\LaravelPwa\Push\WebPush::subscriptionModel();
    })->throws(InvalidArgumentException::class, 'pwa.push.model must be');
});

describe('subscriptions', function (): void {
    it('requires a signed-in user', function (): void {
        $this->postJson('/pwa/push/subscriptions', browserSubscription())->assertUnauthorized();
        $this->deleteJson('/pwa/push/subscriptions', ['endpoint' => FCM])->assertUnauthorized();
    });

    it('stores the subscription of the signed-in user', function (): void {
        Event::fake([PushSubscribed::class]);
        $user = PushUser::make('Alice');

        $this->actingAs($user)
            ->withHeader('User-Agent', 'Chrome/140')
            ->postJson('/pwa/push/subscriptions', browserSubscription())
            ->assertCreated()
            ->assertJson(['subscribed' => true, 'user' => Pwa::identityHash($user->getKey())])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionHas(PushSubscriptionController::SESSION_KEY, [PushSubscription::hashEndpoint(FCM)]);

        $subscription = $user->pushSubscriptions()->sole();
        expect($subscription->endpoint)->toBe(FCM)
            ->and($subscription->content_encoding)->toBe('aes128gcm')
            ->and($subscription->user_agent)->toBe('Chrome/140')
            ->and($subscription->toArray())->not->toHaveKeys(['public_key', 'auth_token']);
        Event::assertDispatched(PushSubscribed::class);

        // The same browser again: an update, not a second row.
        $this->actingAs($user)->postJson('/pwa/push/subscriptions', browserSubscription(extra: ['expirationTime' => 1_900_000_000_000]))->assertOk();
        expect($user->pushSubscriptions()->sole()->expires_at?->getTimestamp())->toBe(1_900_000_000);
    });

    it('accepts only https endpoints of the allowed push services', function (string $endpoint): void {
        $this->actingAs(PushUser::make('Alice'))
            ->postJson('/pwa/push/subscriptions', browserSubscription($endpoint))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint' => 'allowed push service']);

        expect(PushSubscription::query()->count())->toBe(0);
    })->with([
        'internal host' => 'https://10.0.0.5/metadata',
        'other host' => 'https://evil.test/push',
        'look-alike' => 'https://fcm.googleapis.com.evil.test/push',
        'plain http' => 'http://fcm.googleapis.com/fcm/send/x',
        'explicit port' => 'https://fcm.googleapis.com:8443/fcm/send/x',
        'credentials' => 'https://user@fcm.googleapis.com/fcm/send/x',
    ]);

    it('accepts the major push services', function (string $endpoint): void {
        $this->actingAs(PushUser::make('Alice'))->postJson('/pwa/push/subscriptions', browserSubscription($endpoint))->assertCreated();
    })->with([
        'Chrome' => 'https://fcm.googleapis.com/wp/abc',
        'Firefox' => 'https://updates.push.services.mozilla.com/wpush/v2/abc',
        'Edge' => 'https://wns2-par02p.notify.windows.com/w/?token=abc',
        'Safari' => 'https://web.push.apple.com/QGg5',
    ]);

    it('accepts any https host when the allowlist is null', function (): void {
        config(['pwa.push.endpoints' => null]);

        $this->actingAs(PushUser::make('Alice'))->postJson('/pwa/push/subscriptions', browserSubscription('https://push.example.test/x'))->assertCreated();
    });

    it('validates the keys', function (): void {
        $this->actingAs(PushUser::make('Alice'))
            ->postJson('/pwa/push/subscriptions', ['endpoint' => FCM, 'keys' => ['p256dh' => 'not base64!'], 'contentEncoding' => 'gzip'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth', 'contentEncoding']);
    });

    it('moves a device to the user who subscribes on it', function (): void {
        $alice = PushUser::make('Alice');
        $bob = PushUser::make('Bob');
        subscriptionFor($alice);

        $this->actingAs($bob)->postJson('/pwa/push/subscriptions', browserSubscription())->assertOk();

        expect($alice->pushSubscriptions()->count())->toBe(0)
            ->and($bob->pushSubscriptions()->count())->toBe(1);
    });

    it('keeps the most recently used devices', function (): void {
        config(['pwa.push.max_subscriptions' => 2]);
        $user = PushUser::make('Alice');

        foreach (['a', 'b'] as $index => $device) {
            $this->travelTo(now()->addMinutes($index));
            subscriptionFor($user, "https://fcm.googleapis.com/fcm/send/{$device}");
        }

        $this->travelTo(now()->addMinutes(5));
        Event::fake([PushUnsubscribed::class]);
        subscriptionFor($user, 'https://fcm.googleapis.com/fcm/send/c');

        expect($user->pushSubscriptions()->pluck('endpoint')->sort()->values()->all())
            ->toBe(['https://fcm.googleapis.com/fcm/send/b', 'https://fcm.googleapis.com/fcm/send/c']);
        Event::assertDispatched(PushUnsubscribed::class, fn (PushUnsubscribed $e): bool => str_ends_with($e->subscription->endpoint, '/a'));
    });

    it('renews a subscription only in place of one the user owns', function (): void {
        $alice = PushUser::make('Alice');
        $bob = PushUser::make('Bob');
        subscriptionFor($alice);
        $renewed = 'https://fcm.googleapis.com/fcm/send/device-a-2';

        // Bob signed in meanwhile: the worker's renewal must not subscribe him.
        $this->actingAs($bob)
            ->postJson('/pwa/push/subscriptions', browserSubscription($renewed, ['replaces' => FCM]))
            ->assertNotFound();
        expect($bob->pushSubscriptions()->count())->toBe(0);

        $this->actingAs($alice)
            ->postJson('/pwa/push/subscriptions', browserSubscription($renewed, ['replaces' => FCM]))
            ->assertCreated();
        expect($alice->pushSubscriptions()->pluck('endpoint')->all())->toBe([$renewed]);
    });

    it('unsubscribes only the user\'s own device', function (): void {
        Event::fake([PushUnsubscribed::class]);
        $alice = PushUser::make('Alice');
        $bob = PushUser::make('Bob');
        subscriptionFor($alice);

        $this->actingAs($bob)->deleteJson('/pwa/push/subscriptions', ['endpoint' => FCM])->assertNoContent();
        expect($alice->pushSubscriptions()->count())->toBe(1);
        Event::assertNotDispatched(PushUnsubscribed::class);

        $this->actingAs($alice)->deleteJson('/pwa/push/subscriptions', ['endpoint' => FCM])->assertNoContent();
        expect($alice->pushSubscriptions()->count())->toBe(0);
        Event::assertDispatched(PushUnsubscribed::class);
    });

    it('removes the devices subscribed in the session on logout', function (): void {
        $user = PushUser::make('Alice');
        subscriptionFor($user, 'https://fcm.googleapis.com/fcm/send/laptop');

        $this->actingAs($user)->postJson('/pwa/push/subscriptions', browserSubscription())->assertCreated();

        Auth::guard()->logout();

        expect($user->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://fcm.googleapis.com/fcm/send/laptop'])
            ->and(session(PushSubscriptionController::SESSION_KEY))->toBeNull();
    });

    it('deletes the devices with their owner', function (): void {
        $user = PushUser::make('Alice');
        subscriptionFor($user);

        $user->delete();

        expect(PushSubscription::query()->count())->toBe(0);
    });

    it('prunes subscriptions past their expiration time', function (): void {
        $user = PushUser::make('Alice');
        subscriptionFor($user)->forceFill(['expires_at' => now()->subDay()])->save();
        subscriptionFor($user, 'https://fcm.googleapis.com/fcm/send/b');

        $this->artisan('model:prune', ['--model' => [PushSubscription::class]])->assertSuccessful();

        expect($user->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://fcm.googleapis.com/fcm/send/b']);
    });
});

describe('sending', function (): void {
    it('sends a notification to every device of the user', function (): void {
        $user = PushUser::make('Alice');
        subscriptionFor($user);
        subscriptionFor($user, 'https://updates.push.services.mozilla.com/wpush/v2/b');

        $user->notify(new OrderShipped);

        expect($this->transport()->sent)->toHaveCount(2)
            ->and($this->transport()->sent[0]['options'])->toBe(['TTL' => 2_419_200, 'urgency' => 'normal'])
            ->and($this->transport()->payload())->toBe([
                'pwa' => 1,
                'title' => 'Order shipped',
                'user' => Pwa::identityHash($user->getKey()),
                'options' => [
                    'body' => 'Order 1001 is on its way.',
                    'actions' => [],
                    'data' => ['url' => '/orders/1001', 'actions' => []],
                ],
            ])
            ->and($user->pushSubscriptions()->whereNotNull('last_used_at')->count())->toBe(2);
    });

    it('also answers to the webpush channel name', function (): void {
        $user = PushUser::make('Alice');
        subscriptionFor($user);

        $user->notify(new OrderShipped('webpush'));

        expect($this->transport()->sent)->toHaveCount(1);
    });

    it('deletes subscriptions the push service no longer knows', function (int $status): void {
        Event::fake([PushSubscriptionExpired::class]);
        $user = PushUser::make('Alice');
        subscriptionFor($user);
        $this->transport()->statuses[FCM] = $status;

        $user->notify(new OrderShipped);

        expect($user->pushSubscriptions()->count())->toBe(0);
        Event::assertDispatched(PushSubscriptionExpired::class, fn (PushSubscriptionExpired $e): bool => $e->report->status === $status);
    })->with([404, 410]);

    it('keeps the subscription on other failures', function (): void {
        Event::fake([PushFailed::class]);
        $user = PushUser::make('Alice');
        subscriptionFor($user);
        $this->transport()->statuses[FCM] = 429;

        $user->notify(new OrderShipped);

        expect($user->pushSubscriptions()->count())->toBe(1);
        Event::assertDispatched(PushFailed::class, fn (PushFailed $e): bool => $e->report->reason === 'HTTP 429');
    });

    it('never contacts hosts outside the allowlist, even for stored subscriptions', function (): void {
        Event::fake([PushFailed::class]);
        $user = PushUser::make('Alice');
        config(['pwa.push.endpoints' => null]);
        subscriptionFor($user, 'https://push.example.test/x');
        config(['pwa.push.endpoints' => ['fcm.googleapis.com']]);

        $reports = WebPush::send($user, WebPushMessage::create('Hi'));

        expect($this->transport()->sent)->toBe([])
            ->and($reports[0]->success)->toBeFalse();
        Event::assertDispatched(PushFailed::class);
    });

    it('sends to one subscription or a list of them', function (): void {
        $user = PushUser::make('Alice');
        $subscription = subscriptionFor($user);

        WebPush::send($subscription, WebPushMessage::create('One'));
        WebPush::send($user->pushSubscriptions()->get(), WebPushMessage::create('List')->public());

        expect($this->transport()->payload(0)['user'])->toBe(Pwa::identityHash($user->getKey()))
            ->and($this->transport()->payload(1))->not->toHaveKey('user');
    });

    it('explains a notification without toWebPush', function (): void {
        $user = PushUser::make('Alice');

        $user->notify(new class extends Notification
        {
            /** @return list<string> */
            public function via(object $notifiable): array
            {
                return [WebPushChannel::class];
            }
        });
    })->throws(InvalidArgumentException::class, 'must define toWebPush');
});

describe('fake', function (): void {
    it('records messages instead of sending them', function (): void {
        $fake = WebPush::fake();
        $alice = PushUser::make('Alice');
        $bob = PushUser::make('Bob');
        subscriptionFor($alice);

        $alice->notify(new OrderShipped);

        WebPush::assertSentTo($alice, fn (WebPushMessage $message): bool => $message->getTitle() === 'Order shipped');
        WebPush::assertNotSentTo($bob);
        WebPush::assertSentCount(1);
        expect($fake->sent($alice))->toHaveCount(1)
            ->and($this->transport()->sent)->toBe([]);
    });

    it('asserts nothing was sent', function (): void {
        WebPush::fake();

        WebPush::assertNothingSent();
    });

    it('fails when nothing matches', function (): void {
        WebPush::fake();

        WebPush::assertSentTo(PushUser::make('Alice'));
    })->throws(ExpectationFailedException::class, 'No matching web push was sent');
});

describe('doctor', function (): void {
    it('checks the library, the keys and the table', function (): void {
        $this->artisan('pwa:doctor')
            ->expectsOutputToContain('Web push library (minishlink/web-push)')
            ->expectsOutputToContain('VAPID keys and subject')
            ->expectsOutputToContain('Push subscriptions table (pwa_push_subscriptions)')
            ->assertSuccessful();
    });

    it('reports missing keys, a wrong subject and an open allowlist', function (): void {
        config(['pwa.push.vapid.subject' => 'ops@pwa.test', 'pwa.push.endpoints' => null]);

        $this->artisan('pwa:doctor')
            ->expectsOutputToContain('PWA_VAPID_SUBJECT must be a mailto: address or an https:// URL')
            ->expectsOutputToContain('the server sends to any host')
            ->assertSuccessful();

        config(['pwa.push.vapid.private_key' => null, 'pwa.push.handle_in_worker' => false]);

        $this->artisan('pwa:doctor')
            ->expectsOutputToContain('VAPID keys are missing: php artisan pwa:push:vapid')
            ->expectsOutputToContain('no sw.import script shows the notifications')
            ->assertSuccessful();
    });
});

it('has no analytics route unless pwa.push.analytics is on', function (): void {
    $this->postJson('/pwa/push/events', ['events' => [['type' => 'shown']]])->assertNotFound();
    expect(app(ServiceWorkerBuilder::class)->runtimeConfig()['push']['events'])->toBeNull();
});
