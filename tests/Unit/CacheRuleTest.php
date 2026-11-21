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

it('accepts the smallest limits and rejects anything below', function (): void {
    $rule = CacheRule::for('/api/*')->networkFirst()->maxEntries(1)->maxAge(1)->timeout(1);

    expect($rule->toArray())->toMatchArray(['maxEntries' => 1, 'maxAge' => 1, 'timeout' => 1])
        ->and(fn (): CacheRule => CacheRule::for('/x')->maxEntries(0))->toThrow(InvalidArgumentException::class, '[max_entries] must be at least 1')
        ->and(fn (): CacheRule => CacheRule::for('/x')->maxAge())->toThrow(InvalidArgumentException::class, '[max_age] must be at least 1 second')
        ->and(fn (): CacheRule => CacheRule::for('/x')->networkFirst()->timeout(0))->toThrow(InvalidArgumentException::class, '[timeout] must be at least 1 second');
});

it('adds up max_age from every unit', function (): void {
    expect(CacheRule::for('/x')->maxAge(seconds: 1, minutes: 2, hours: 3, days: 4)->toArray()['maxAge'])->toBe(1 + 120 + 10800 + 345600);
});

it('derives a stable name from the pattern and the destinations', function (): void {
    $a = CacheRule::for('/api/*')->getName();

    expect($a)->toMatch('/^r[a-f0-9]{8}$/')
        ->and($a)->toBe('r'.substr(hash('sha256', '/api/*|'), 0, 8))
        ->and(CacheRule::for('/api/*')->getName())->toBe($a)
        ->and(CacheRule::for('/other/*')->getName())->not->toBe($a)
        ->and(CacheRule::for(null)->destination('image', 'font')->getName())->toBe('r'.substr(hash('sha256', '|image,font'), 0, 8));
});

it('lists each destination once', function (): void {
    expect(CacheRule::for(null)->destination('image', 'image', 'font')->destination('font')->toArray()['destinations'])->toBe(['image', 'font']);
});

it('sends an empty pattern as null', function (): void {
    expect(CacheRule::for('')->destination('image')->toArray()['match'])->toBeNull();
});

it('allows a timeout only with network-first', function (): void {
    expect(fn (): CacheRule => CacheRule::for('/x')->cacheFirst()->timeout(3)->validate())->toThrow(InvalidArgumentException::class);
});

it('names an unknown destination and lists the allowed ones', function (): void {
    expect(fn (): CacheRule => CacheRule::for(null)->destination('iframe'))
        ->toThrow(InvalidArgumentException::class, 'Unknown request destination [iframe]. Allowed: image, font, style, script, audio, video, track, manifest, worker, document.');
});
