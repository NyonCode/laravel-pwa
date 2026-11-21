<?php

use NyonCode\LaravelPwa\ServiceWorker\CacheRule;
use NyonCode\LaravelPwa\ServiceWorker\CacheStrategy;

it('builds a rule fluently', function (): void {
    $rule = CacheRule::for('/api/catalog*')->staleWhileRevalidate()->name('catalog')->maxEntries(200)->maxAge(hours: 2, days: 1);

    expect($rule->toArray())->toBe([
        'match' => '/api/catalog*',
        'strategy' => 'stale-while-revalidate',
        'cache' => 'catalog',
        'shared' => false,
        'maxEntries' => 200,
        'maxAge' => 93600,
        'timeout' => null,
        'destinations' => [],
    ]);
});

it('builds a rule from config', function (): void {
    $rule = CacheRule::fromArray([
        'match' => '/avatars/*',
        'strategy' => 'cache-first',
        'cache' => 'avatars',
        'destination' => 'image',
        'max_entries' => '50',
        'max_age' => 3600,
        'shared' => true,
    ]);

    expect($rule->toArray())->toMatchArray([
        'strategy' => 'cache-first',
        'cache' => 'avatars',
        'shared' => true,
        'maxEntries' => 50,
        'maxAge' => 3600,
        'destinations' => ['image'],
    ]);
});

it('supports every strategy', function (CacheStrategy $strategy, string $method): void {
    expect(CacheRule::for('/x')->{$method}()->getStrategy())->toBe($strategy);
})->with([
    [CacheStrategy::NetworkFirst, 'networkFirst'],
    [CacheStrategy::CacheFirst, 'cacheFirst'],
    [CacheStrategy::StaleWhileRevalidate, 'staleWhileRevalidate'],
    [CacheStrategy::NetworkOnly, 'networkOnly'],
    [CacheStrategy::CacheOnly, 'cacheOnly'],
]);

it('derives a stable cache name when none is given', function (): void {
    $a = CacheRule::for('/api/*')->getName();

    expect($a)->toMatch(CacheRule::NAME_PATTERN)
        ->and(CacheRule::for('/api/*')->getName())->toBe($a)
        ->and(CacheRule::for('/other/*')->getName())->not->toBe($a);
});

it('accepts a timeout only for network-first', function (): void {
    expect(CacheRule::for('/x')->networkFirst(timeout: 2)->toArray()['timeout'])->toBe(2);

    CacheRule::for('/x')->timeout(2)->cacheFirst()->toArray();
})->throws(InvalidArgumentException::class, 'only applies to network-first');

it('rejects invalid rules with a clear message', function (array $rule, string $message): void {
    expect(fn (): CacheRule => CacheRule::fromArray($rule))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown key' => [['match' => '/x', 'stratgy' => 'cache-first'], 'Unknown cache rule key(s) [stratgy]'],
    'unknown strategy' => [['match' => '/x', 'strategy' => 'fastest'], 'Unknown cache strategy [fastest]'],
    'no match or destination' => [['strategy' => 'cache-first'], 'needs a path pattern'],
    'relative pattern' => [['match' => 'api/*'], "must start with '/'"],
    'bad name' => [['match' => '/x', 'cache' => 'My Cache'], 'Cache name [My Cache]'],
    'zero entries' => [['match' => '/x', 'max_entries' => 0], 'at least 1'],
    'non-integer age' => [['match' => '/x', 'max_age' => 'a day'], '[max_age] must be an integer'],
    'unknown destination' => [['destination' => 'picture'], 'Unknown request destination [picture]'],
    'non-string match' => [['match' => ['/x']], 'must be a path pattern string'],
]);

it('knows which strategies store responses', function (): void {
    expect(CacheStrategy::NetworkOnly->stores())->toBeFalse()
        ->and(CacheStrategy::CacheFirst->stores())->toBeTrue();
});
