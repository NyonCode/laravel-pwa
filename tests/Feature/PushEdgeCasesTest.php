<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use NyonCode\LaravelPwa\Events\PushSent;
use NyonCode\LaravelPwa\Facades\WebPush;
use NyonCode\LaravelPwa\Push\PushSubscription;
use NyonCode\LaravelPwa\Push\SendWebPush;
use NyonCode\LaravelPwa\Push\WebPushMessage;
use NyonCode\LaravelPwa\Tests\Fixtures\PushUser;
use NyonCode\LaravelPwa\Tests\Support\WithPush;

uses(WithPush::class);

function edgeSubscribe(PushUser $user, string $device, array $extra = []): PushSubscription
{
    return app(NyonCode\LaravelPwa\Push\WebPush::class)->subscribe($user, [
        'endpoint' => "https://fcm.googleapis.com/fcm/send/{$device}",
        'publicKey' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'authToken' => 'tBHItJI5svbpez7KI4CCXg',
        ...$extra,
    ]);
}

it('stores the defaults of a subscription', function (): void {
    $this->freezeSecond();
    $subscription = edgeSubscribe(PushUser::make('Alice'), 'a', ['userAgent' => str_repeat('x', 400)]);

    expect($subscription->content_encoding)->toBe('aes128gcm')
        ->and(mb_strlen((string) $subscription->user_agent))->toBe(300)
        ->and($subscription->last_used_at?->equalTo(now()))->toBeTrue()
        ->and(edgeSubscribe(PushUser::make('Bob'), 'b', ['contentEncoding' => 'aesgcm'])->content_encoding)->toBe('aesgcm');
});

it('keeps exactly max_subscriptions devices, the most recently used ones', function (): void {
    config()->set('pwa.push.max_subscriptions', 2);
    $alice = PushUser::make('Alice');

    foreach (['a', 'b', 'c'] as $i => $device) {
        $this->travel($i)->minutes();
        edgeSubscribe($alice, $device);
    }

    expect($alice->pushSubscriptions()->pluck('endpoint')->sort()->values()->all())
        ->toBe(['https://fcm.googleapis.com/fcm/send/b', 'https://fcm.googleapis.com/fcm/send/c']);
});

it('tells whether unsubscribe removed anything', function (): void {
    $alice = PushUser::make('Alice');
    $sub = edgeSubscribe($alice, 'a');

    expect(WebPush::unsubscribe($alice, $sub->endpoint))->toBeTrue()
        ->and(WebPush::unsubscribe($alice, $sub->endpoint))->toBeFalse();
});

it('matches endpoint hosts case-insensitively and rejects odd URLs', function (): void {
    expect(WebPush::allows('https://FCM.googleapis.com/fcm/send/x'))->toBeTrue()
        ->and(WebPush::allows('https://web.push.apple.com/x'))->toBeTrue()
        ->and(WebPush::allows('https://fcm.googleapis.com:8443/x'))->toBeFalse()
        ->and(WebPush::allows('https://user@fcm.googleapis.com/x'))->toBeFalse()
        ->and(WebPush::allows('http://fcm.googleapis.com/x'))->toBeFalse()
        ->and(WebPush::allows('https://evil.test/x'))->toBeFalse();

    config()->set('pwa.push.endpoints');
    expect(WebPush::allows('https://evil.test/x'))->toBeTrue()
        ->and(WebPush::allows('http://evil.test/x'))->toBeFalse();
});

it('marks a delivered subscription as used and sends each subscription once', function (): void {
    $alice = PushUser::make('Alice');
    $sub = edgeSubscribe($alice, 'a');
    $sub->forceFill(['last_used_at' => now()->subDay()])->save();

    WebPush::send($alice, WebPushMessage::create('Hi'));

    expect($sub->fresh()->last_used_at->isToday())->toBeTrue()
        ->and($this->transport()->sent)->toHaveCount(1);
});

it('skips endpoints outside the allowlist but still sends to the others', function (): void {
    Event::fake([PushSent::class]);
    $alice = PushUser::make('Alice');
    edgeSubscribe($alice, 'ok');
    $bad = edgeSubscribe($alice, 'bad');
    $bad->forceFill(['endpoint' => 'https://evil.test/x'])->save();

    $reports = WebPush::send($alice, WebPushMessage::create('Hi'));

    expect($reports)->toHaveCount(2)
        ->and($this->transport()->sent)->toHaveCount(1);
    Event::assertDispatched(PushSent::class, fn (PushSent $e): bool => $e->delivered() === 1 && $e->failed() === 1);
});

it('sends to one subscription or a list of them', function (): void {
    $alice = PushUser::make('Alice');
    $a = edgeSubscribe($alice, 'a');
    $b = edgeSubscribe($alice, 'b');

    WebPush::send($a, WebPushMessage::create('One'));
    WebPush::send([$a, $b, 'not a subscription'], WebPushMessage::create('List'));

    expect($this->transport()->sent)->toHaveCount(3);
});

it('queues on the default connection when none is configured, and keeps mixed recipients whole', function (): void {
    Bus::fake();
    config()->set('pwa.push.queue', ['chunk' => 10, 'connection' => '', 'queue' => '']);
    $plain = new class
    {
        public function routeNotificationForWebPush(): array
        {
            return [];
        }
    };

    WebPush::queue([PushUser::make('Alice'), $plain], WebPushMessage::create('Hi'));

    Bus::assertDispatched(SendWebPush::class, fn (SendWebPush $job): bool => $job->connection === null && $job->queue === null
        && is_array($job->notifiables) && count($job->notifiables) === 2);
});
