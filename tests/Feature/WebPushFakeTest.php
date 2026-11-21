<?php

use NyonCode\LaravelPwa\Facades\WebPush;
use NyonCode\LaravelPwa\Push\WebPushMessage;
use NyonCode\LaravelPwa\Tests\Fixtures\PushUser;
use NyonCode\LaravelPwa\Tests\Support\WithPush;
use PHPUnit\Framework\ExpectationFailedException;

uses(WithPush::class);

function fakeSubscribe(PushUser $user, string $device): void
{
    app(NyonCode\LaravelPwa\Push\WebPush::class)->subscribe($user, [
        'endpoint' => "https://fcm.googleapis.com/fcm/send/{$device}",
        'publicKey' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'authToken' => 'tBHItJI5svbpez7KI4CCXg',
    ]);
}

it('reports every subscription as delivered without sending', function (): void {
    $alice = PushUser::make('Alice');
    fakeSubscribe($alice, 'a');
    fakeSubscribe($alice, 'b');
    $fake = WebPush::fake();

    $reports = WebPush::send($alice, WebPushMessage::create('Hi'));

    expect($reports)->toHaveCount(2)
        ->and($reports[0]->success)->toBeTrue()
        ->and($reports[0]->status)->toBe(201)
        ->and($this->transport()->sent)->toBe([])
        ->and($fake->sent())->toHaveCount(1);
});

it('still validates messages', function (): void {
    WebPush::fake();

    expect(fn () => WebPush::send(PushUser::make('Alice'), WebPushMessage::create('')))->toThrow(InvalidArgumentException::class);
});

it('tells recipients apart, also as different model instances', function (): void {
    $alice = PushUser::make('Alice');
    $bob = PushUser::make('Bob');
    $fake = WebPush::fake();

    WebPush::send($alice, WebPushMessage::create('For Alice'));

    $fake->assertSentTo(PushUser::query()->find($alice->getKey()));
    $fake->assertSentTo($alice, fn (WebPushMessage $m): bool => $m->getTitle() === 'For Alice');
    $fake->assertNotSentTo($bob);
    $fake->assertNotSentTo($alice, fn (WebPushMessage $m): bool => $m->getTitle() === 'Other');
    expect($fake->sent($bob))->toBe([])
        ->and($fake->sent($alice)[0]->getTitle())->toBe('For Alice');
});

it('fails with a message naming the recipient', function (): void {
    $alice = PushUser::make('Alice');
    $fake = WebPush::fake();

    expect(fn () => $fake->assertSentTo($alice))->toThrow(ExpectationFailedException::class, 'No matching web push was sent to '.PushUser::class.'.');

    WebPush::send($alice, WebPushMessage::create('Hi'));

    expect(fn () => $fake->assertNotSentTo($alice))->toThrow(ExpectationFailedException::class, 'An unexpected web push was sent to '.PushUser::class.'.')
        ->and(fn () => $fake->assertSent(fn (WebPushMessage $m): bool => $m->getTitle() === 'Nope'))->toThrow(ExpectationFailedException::class, 'No matching web push was sent.')
        ->and(fn () => $fake->assertSentCount(2))->toThrow(ExpectationFailedException::class, 'Expected 2 web push messages, 1 sent.')
        ->and(fn () => $fake->assertNothingSent())->toThrow(ExpectationFailedException::class);
});

it('records queued recipients and counts the jobs a real queue would make', function (): void {
    config()->set('pwa.push.queue.chunk', 2);
    $users = collect(['A', 'B', 'C', 'D', 'E'])->map(fn (string $name): PushUser => PushUser::make($name));
    $fake = WebPush::fake();

    expect(WebPush::queue($users->all(), WebPushMessage::create('Sale')))->toBe(3)
        ->and(WebPush::queue(PushUser::query()->whereKey($users[0]->getKey()), WebPushMessage::create('One')))->toBe(1);

    $fake->assertQueued(fn (WebPushMessage $m): bool => $m->getTitle() === 'Sale', $users[4]);
    $fake->assertQueued(fn (WebPushMessage $m): bool => $m->getTitle() === 'One', $users[0]);
    expect(fn () => $fake->assertQueued(fn (WebPushMessage $m): bool => $m->getTitle() === 'One', $users[1]))
        ->toThrow(ExpectationFailedException::class, 'No matching web push was queued for '.PushUser::class.'.')
        ->and(fn () => $fake->assertQueued(fn (WebPushMessage $m): bool => false))
        ->toThrow(ExpectationFailedException::class, 'No matching web push was queued.')
        ->and(fn () => $fake->assertNothingQueued())
        ->toThrow(ExpectationFailedException::class, '2 unexpected web push messages were queued.');
});

it('validates queued messages too', function (): void {
    $fake = WebPush::fake();

    expect(fn () => WebPush::queue([PushUser::make('Alice')], WebPushMessage::create('')))->toThrow(InvalidArgumentException::class);
    $fake->assertNothingQueued();
});
