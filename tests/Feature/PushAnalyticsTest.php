<?php

use Illuminate\Support\Facades\Event;
use NyonCode\LaravelPwa\Events\PushClicked;
use NyonCode\LaravelPwa\Events\PushDismissed;
use NyonCode\LaravelPwa\Events\PushDisplayed;
use NyonCode\LaravelPwa\Push\WebPushMessage;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use NyonCode\LaravelPwa\Tests\Support\WithPushAnalytics;

uses(WithPushAnalytics::class);

it('turns worker reports into events, without a session', function (): void {
    Event::fake([PushDisplayed::class, PushClicked::class, PushDismissed::class]);

    $response = $this->withHeader('User-Agent', 'Safari/18')->postJson('/pwa/push/events', ['events' => [
        ['type' => 'shown', 'id' => 'campaign-7', 'tag' => 'order-1'],
        ['type' => 'click', 'id' => 'campaign-7', 'tag' => 'order-1', 'action' => 'track'],
        ['type' => 'close', 'id' => 'campaign-7', 'tag' => 'order-1'],
    ]]);

    $response->assertStatus(202)->assertCookieMissing(config('session.cookie'));
    Event::assertDispatched(PushDisplayed::class, fn (PushDisplayed $e): bool => $e->id === 'campaign-7' && $e->tag === 'order-1' && $e->userAgent === 'Safari/18');
    Event::assertDispatched(PushClicked::class, fn (PushClicked $e): bool => $e->action === 'track');
    Event::assertDispatched(PushDismissed::class);
});

it('rejects unknown event types and too many events', function (): void {
    $this->postJson('/pwa/push/events', ['events' => [['type' => 'opened']]])->assertUnprocessable();
    $this->postJson('/pwa/push/events', ['events' => array_fill(0, 21, ['type' => 'shown'])])->assertUnprocessable();
});

it('tells the worker where to report', function (): void {
    expect(app(ServiceWorkerBuilder::class)->runtimeConfig()['push']['events'])->toBe('/pwa/push/events');
});

it('carries the message id in the notification data', function (): void {
    expect(WebPushMessage::create('Hi')->id('campaign-7')->toPayload()['options']['data']['id'])->toBe('campaign-7');
});
