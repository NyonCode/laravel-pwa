<?php

use Illuminate\Support\Facades\File;
use NyonCode\LaravelPwa\Facades\Pwa;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;

afterEach(fn () => Pwa::serviceWorker()->flush());

function workerRules(): array
{
    return app(ServiceWorkerBuilder::class)->runtimeConfig()['rules'];
}

it('ends with the default image and font rule', function (): void {
    expect(workerRules())->toBe([[
        'match' => null,
        'strategy' => 'stale-while-revalidate',
        'cache' => 'images',
        'shared' => false,
        'maxEntries' => 120,
        'maxAge' => 60 * 60 * 24 * 30,
        'timeout' => null,
        'destinations' => ['image', 'font'],
    ]]);
});

it('can disable the default image rule', function (): void {
    config(['pwa.cache.images.enabled' => false]);

    expect(workerRules())->toBe([]);
});

it('orders config rules, fluent rules, cache.api, then images', function (): void {
    config([
        'pwa.cache.rules' => [['match' => '/api/live', 'strategy' => 'network-only', 'cache' => 'live']],
        'pwa.cache.api' => ['/api/*'],
    ]);
    Pwa::serviceWorker()->cache('/reports/*')->cacheFirst()->name('reports')->maxAge(hours: 1);

    expect(array_column(workerRules(), 'cache'))->toBe(['live', 'reports', 'api', 'images'])
        ->and(workerRules()[1])->toMatchArray(['strategy' => 'cache-first', 'maxAge' => 3600])
        ->and(workerRules()[2])->toMatchArray(['match' => '/api/*', 'strategy' => 'network-first']);
});

it('names the config entry that is invalid', function (): void {
    config(['pwa.cache.rules' => [['match' => '/ok'], ['match' => '/x', 'strategy' => 'fastest']]]);

    workerRules();
})->throws(InvalidArgumentException::class, 'pwa.cache.rules.1: Unknown cache strategy [fastest]');

it('refuses one cache name for a shared and a per-user rule', function (): void {
    config(['pwa.cache.rules' => [
        ['match' => '/public/*', 'cache' => 'data', 'shared' => true],
        ['match' => '/private/*', 'cache' => 'data'],
    ]]);

    workerRules();
})->throws(InvalidArgumentException::class, 'used by both a shared and a per-user rule');

it('changes the worker version when the rules change', function (): void {
    $before = app(ServiceWorkerBuilder::class)->runtimeConfig()['version'];

    Pwa::serviceWorker()->cache('/api/*')->staleWhileRevalidate();

    expect(app(ServiceWorkerBuilder::class)->runtimeConfig()['version'])->not->toBe($before);
});

it('loads sw.extend scripts after the runtime and versions them by content', function (): void {
    File::put(public_path('pwa-extend.js'), '// v1');
    config(['pwa.sw.import' => ['/push-sw.js'], 'pwa.sw.extend' => ['/pwa-extend.js']]);

    $builder = app(ServiceWorkerBuilder::class);
    $script = $builder->build();
    $v1 = $builder->runtimeConfig()['version'];

    expect(strpos($script, 'importScripts("/push-sw.js")'))->toBeLessThan(strpos($script, 'self.PwaWorker = Object.freeze'))
        ->and(strpos($script, 'self.PwaWorker = Object.freeze'))->toBeLessThan(strpos($script, 'importScripts("/pwa-extend.js")'));

    File::put(public_path('pwa-extend.js'), '// v2');
    expect($builder->runtimeConfig()['version'])->not->toBe($v1);

    File::delete(public_path('pwa-extend.js'));
});

it('rejects sw.extend values that are not strings', function (): void {
    config(['pwa.sw.extend' => [['/x.js']]]);

    app(ServiceWorkerBuilder::class)->extensions();
})->throws(InvalidArgumentException::class, '[pwa.sw.extend] must be a list of strings');

it('fails pwa:build with the rule error', function (): void {
    config(['pwa.cache.rules' => [['match' => 'relative']]]);

    $this->artisan('pwa:build')
        ->expectsOutputToContain("must start with '/'")
        ->assertFailed();
});

it('reports invalid rules in pwa:doctor', function (): void {
    config(['pwa.cache.rules' => [['match' => '/x', 'strategy' => 'fastest']]]);

    $this->artisan('pwa:doctor')->expectsOutputToContain('Unknown cache strategy')->assertSuccessful();
    $this->artisan('pwa:doctor', ['--ci' => true])->assertFailed();
});

it('warns when a shared rule covers paths that are never cached', function (): void {
    config(['pwa.cache.rules' => [['match' => '/*', 'cache' => 'everything', 'shared' => true]]]);

    $this->artisan('pwa:doctor')->expectsOutputToContain('Shared cache rule [everything]')->assertSuccessful();
});
