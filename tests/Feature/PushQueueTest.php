<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use NyonCode\LaravelPwa\Events\PushSent;
use NyonCode\LaravelPwa\Facades\Pwa;
use NyonCode\LaravelPwa\Facades\WebPush;
use NyonCode\LaravelPwa\Push\PushSubscription;
use NyonCode\LaravelPwa\Push\SendWebPush;
use NyonCode\LaravelPwa\Push\WebPushMessage;
use NyonCode\LaravelPwa\Tests\Fixtures\PushUser;
use NyonCode\LaravelPwa\Tests\Support\WithPush;
use PHPUnit\Framework\ExpectationFailedException;

uses(WithPush::class);

function subscribe(PushUser $user, string $device): PushSubscription
{
    return app(NyonCode\LaravelPwa\Push\WebPush::class)->subscribe($user, [
        'endpoint' => "https://fcm.googleapis.com/fcm/send/{$device}",
        'publicKey' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'authToken' => 'tBHItJI5svbpez7KI4CCXg',
    ]);
}

it('queues one job per chunk of a query', function (): void {
    Bus::fake();
    config()->set('pwa.push.queue', ['chunk' => 2, 'connection' => 'redis', 'queue' => 'push']);
    foreach (['Alice', 'Bob', 'Carol'] as $name) {
        PushUser::make($name);
    }

    $jobs = WebPush::queue(PushUser::query(), WebPushMessage::create('Sale starts now'));

    expect($jobs)->toBe(2);
    Bus::assertDispatchedTimes(SendWebPush::class, 2);
    Bus::assertDispatched(SendWebPush::class, fn (SendWebPush $job): bool => $job->connection === 'redis' && $job->queue === 'push' && count($job->notifiables) === 2);
});

it('chunks a list and sends every recipient its own payload when the job runs', function (): void {
    $alice = PushUser::make('Alice');
    $bob = PushUser::make('Bob');
    subscribe($alice, 'alice');
    subscribe($bob, 'bob');

    config()->set('queue.default', 'sync');

    expect(WebPush::queue([$alice, $bob], WebPushMessage::create('Hi')))->toBe(1);

    expect($this->transport()->sent)->toHaveCount(2)
        ->and($this->transport()->payload(0)['user'])->toBe(Pwa::identityHash($alice->getKey()))
        ->and($this->transport()->payload(1)['user'])->toBe(Pwa::identityHash($bob->getKey()));
});

it('serializes Eloquent recipients as ids only', function (): void {
    $alice = PushUser::make('Alice');
    Bus::fake();

    WebPush::queue([$alice], WebPushMessage::create('Hi'));

    Bus::assertDispatched(SendWebPush::class, function (SendWebPush $job): bool {
        $serialized = serialize($job);

        return str_contains($serialized, 'ModelIdentifier') && ! str_contains($serialized, 'alice@pwa.test');
    });
});

it('checks the message before queueing anything', function (): void {
    Bus::fake();

    expect(fn () => WebPush::queue([PushUser::make('Alice')], WebPushMessage::create('')))->toThrow(InvalidArgumentException::class);
    Bus::assertNothingDispatched();
});

it('summarises every send in PushSent', function (): void {
    Event::fake([PushSent::class]);
    $alice = PushUser::make('Alice');
    subscribe($alice, 'ok');
    $gone = subscribe($alice, 'gone');
    $this->transport()->statuses[$gone->endpoint] = 410;

    WebPush::send($alice, WebPushMessage::create('Hi'));

    Event::assertDispatched(PushSent::class, fn (PushSent $event): bool => $event->notifiable === $alice
        && $event->delivered() === 1 && $event->expired() === 1 && $event->failed() === 0);
});

it('records queued messages in the fake', function (): void {
    $fake = WebPush::fake();
    $alice = PushUser::make('Alice');

    WebPush::queue(PushUser::query(), WebPushMessage::create('Sale'));

    $fake->assertQueued(fn (WebPushMessage $message): bool => $message->getTitle() === 'Sale', $alice);
    $fake->assertNothingSent();
    expect(fn () => $fake->assertNothingQueued())->toThrow(ExpectationFailedException::class);
});
