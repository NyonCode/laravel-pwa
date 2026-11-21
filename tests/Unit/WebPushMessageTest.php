<?php

use NyonCode\LaravelPwa\Push\Vapid;
use NyonCode\LaravelPwa\Push\WebPushMessage;

it('builds the payload the worker reads', function (): void {
    $message = WebPushMessage::create('Invoice paid')
        ->body('Invoice 42 was paid.')
        ->icon('/img/invoice.png')
        ->badge('/img/badge.png')
        ->image('/img/chart.png')
        ->url('/invoices/42')
        ->tag('invoice-42', renotify: true)
        ->requireInteraction()
        ->silent(false)
        ->lang('en')
        ->dir('ltr')
        ->timestamp(new DateTimeImmutable('2026-11-21 10:00:00 UTC'))
        ->action('open', 'Open', '/invoices/42', '/img/open.png')
        ->action('dismiss', 'Dismiss')
        ->data(['invoice' => 42])
        ->appBadge(3);

    expect($message->toPayload('a1b2c3d4e5f60718'))->toBe([
        'pwa' => 1,
        'title' => 'Invoice paid',
        'user' => 'a1b2c3d4e5f60718',
        'appBadge' => 3,
        'options' => [
            'body' => 'Invoice 42 was paid.',
            'icon' => '/img/invoice.png',
            'badge' => '/img/badge.png',
            'image' => '/img/chart.png',
            'tag' => 'invoice-42',
            'renotify' => true,
            'requireInteraction' => true,
            'silent' => false,
            'lang' => 'en',
            'dir' => 'ltr',
            'timestamp' => 1_795_255_200_000,
            'actions' => [
                ['action' => 'open', 'title' => 'Open', 'icon' => '/img/open.png'],
                ['action' => 'dismiss', 'title' => 'Dismiss'],
            ],
            'data' => ['invoice' => 42, 'url' => '/invoices/42', 'actions' => ['open' => '/invoices/42']],
        ],
    ]);
});

it('leaves the recipient out of public messages', function (): void {
    expect(WebPushMessage::create('Maintenance tonight')->public()->toPayload('a1b2c3d4e5f60718'))->not->toHaveKey('user')
        ->and(WebPushMessage::create('x')->isPrivate())->toBeTrue();
});

it('builds Declarative Web Push with absolute URLs', function (): void {
    $payload = WebPushMessage::create('Order shipped')
        ->body('On its way.')
        ->url('/orders/7')
        ->action('track', 'Track', '/orders/7/track')
        ->appBadge(1)
        ->declarative()
        ->toPayload('a1b2c3d4e5f60718');

    expect($payload)->toBe([
        'web_push' => 8030,
        'notification' => [
            'title' => 'Order shipped',
            'body' => 'On its way.',
            'navigate' => url('/orders/7'),
            'actions' => [['action' => 'track', 'title' => 'Track', 'navigate' => url('/orders/7/track')]],
        ],
        'app_badge' => '1',
        'mutable' => true,
    ]);
});

it('reads delivery options from the config unless the message sets them', function (): void {
    config(['pwa.push.ttl' => 600, 'pwa.push.urgency' => 'low']);

    expect(WebPushMessage::create('x')->deliveryOptions())->toBe(['TTL' => 600, 'urgency' => 'low'])
        ->and(WebPushMessage::create('x')->ttl(60)->urgency('high')->topic('orders-7')->deliveryOptions())
        ->toBe(['TTL' => 60, 'urgency' => 'high', 'topic' => 'orders-7']);
});

it('rejects invalid messages', function (Closure $build, string $error): void {
    expect(fn () => $build())->toThrow(InvalidArgumentException::class, $error);
})->with([
    'no title' => [fn (): array => WebPushMessage::create()->toPayload(), 'needs a title'],
    'declarative without url' => [fn (): array => WebPushMessage::create('x')->declarative()->toPayload(), 'needs a url()'],
    'urgency' => [fn (): WebPushMessage => WebPushMessage::create('x')->urgency('urgent'), 'Invalid push urgency [urgent]'],
    'topic' => [fn (): WebPushMessage => WebPushMessage::create('x')->topic('has spaces'), 'Invalid push topic'],
    'too large' => [fn (): string => WebPushMessage::create('x')->body(str_repeat('a', 5000))->toJson(), 'push services accept at most 4078'],
]);

it('generates VAPID keys browsers accept', function (): void {
    $keys = Vapid::generate();

    expect(Vapid::problem($keys['publicKey'], $keys['privateKey'], 'mailto:ops@pwa.test'))->toBeNull()
        ->and(strlen((string) Vapid::decode($keys['publicKey'])))->toBe(65)
        ->and($keys)->not->toBe(Vapid::generate());
});

$keys = Vapid::generate();

it('explains what is wrong with VAPID settings', function (mixed $public, mixed $private, mixed $subject, string $problem): void {
    expect(Vapid::problem($public, $private, $subject))->toContain($problem);
})->with([
    'missing' => [null, null, 'mailto:a@b.c', 'VAPID keys are missing'],
    'public not a key' => ['abc', 'abc', 'mailto:a@b.c', 'PWA_VAPID_PUBLIC_KEY'],
    'private wrong size' => [$keys['publicKey'], 'YWJj', 'mailto:a@b.c', 'PWA_VAPID_PRIVATE_KEY'],
    'subject' => [$keys['publicKey'], $keys['privateKey'], 'ops@pwa.test', 'PWA_VAPID_SUBJECT'],
]);

it('never sends a negative badge or TTL', function (): void {
    $message = WebPushMessage::create('Hi')->appBadge(-3)->ttl(-5);

    expect($message->toPayload()['appBadge'])->toBe(0)
        ->and($message->deliveryOptions()['TTL'])->toBe(0);
});

it('keeps undelivered messages for 28 days by default', function (): void {
    expect(WebPushMessage::create('Hi')->deliveryOptions())->toBe(['TTL' => 2419200, 'urgency' => 'normal']);
});

it('accepts a payload of exactly the maximum size', function (): void {
    $base = strlen(WebPushMessage::create('x')->toJson());
    $fits = WebPushMessage::create(str_repeat('x', 1 + WebPushMessage::MAX_PAYLOAD - $base));

    expect(strlen($fits->toJson()))->toBe(WebPushMessage::MAX_PAYLOAD)
        ->and(fn (): string => WebPushMessage::create(str_repeat('x', 2 + WebPushMessage::MAX_PAYLOAD - $base))->toJson())
        ->toThrow(InvalidArgumentException::class, 'push services accept at most 4078');
});

it('keeps unicode and slashes readable in the payload', function (): void {
    expect(WebPushMessage::create('Objednávka')->url('/orders/7')->toJson())
        ->toContain('Objednávka')
        ->toContain('"/orders/7"');
});

it('merges data and keeps action URLs out of the visible actions', function (): void {
    $payload = WebPushMessage::create('Hi')
        ->data(['a' => 1])->data(['b' => 2])
        ->action('open', 'Open', '/orders')
        ->action('later', 'Later')
        ->toPayload();

    expect($payload['options']['data'])->toMatchArray(['a' => 1, 'b' => 2, 'actions' => ['open' => '/orders']])
        ->and($payload['options']['actions'])->toBe([['action' => 'open', 'title' => 'Open'], ['action' => 'later', 'title' => 'Later']]);
});

it('carries the display options into a declarative message', function (): void {
    $notification = WebPushMessage::create('Hi')->url('/x')->lang('cs')->dir('ltr')->silent()->tag('t')->declarative()->toPayload()['notification'];

    expect($notification)->toMatchArray(['lang' => 'cs', 'dir' => 'ltr', 'silent' => true, 'tag' => 't']);
});
