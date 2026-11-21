<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Pwa;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;

function warmUser(int $id = 7): User
{
    return (new User)->forceFill(['id' => $id, 'name' => 'Warm']);
}

it('sends no warm-up list and keeps it off by default', function (): void {
    $this->getJson('/pwa/session')->assertJson(['warm' => []]);

    expect(app(Pwa::class)->warmsPages())->toBeFalse()
        ->and(app(Pwa::class)->clientConfig()['warm'])->toBe(['enabled' => false, 'interval' => 60])
        ->and(app(ServiceWorkerBuilder::class)->runtimeConfig()['warm'])->toBeFalse();
});

it('lists configured pages and pages for the signed-in user', function (): void {
    config(['pwa.offline.warm.urls' => ['/dashboard']]);

    app(Pwa::class)->warmUsing(fn (Request $request): array => $request->user()
        ? ['/orders/'.$request->user()->getAuthIdentifier(), '/dashboard']
        : []);

    $this->getJson('/pwa/session')->assertJson(['warm' => ['/dashboard']]);

    $this->actingAs(warmUser())
        ->getJson('/pwa/session')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['warm' => ['/dashboard', '/orders/7']]);

    expect(app(Pwa::class)->clientConfig()['warm']['enabled'])->toBeTrue()
        ->and(app(ServiceWorkerBuilder::class)->runtimeConfig()['warm'])->toBeTrue();
});

it('keeps only same-origin paths outside never_cache', function (): void {
    config(['pwa.offline.warm.urls' => [
        '/a?page=2',
        'http://localhost/b',
        'https://evil.test/c',
        '//evil.test/d',
        'javascript:alert(1)',
        'relative/e',
        '/login',
        '/pwa/session',
        42,
        null,
    ]]);

    expect(app(Pwa::class)->warmUrls(Request::create('http://localhost/')))->toBe(['/a?page=2', '/b']);
});

it('accepts generators and caps the list at offline.warm.max', function (): void {
    config(['pwa.offline.warm.max' => 3]);

    app(Pwa::class)->warmUsing(function (): Generator {
        foreach (range(1, 10) as $i) {
            yield "/p/{$i}";
        }
    });

    expect(app(Pwa::class)->warmUrls(Request::create('/')))->toBe(['/p/1', '/p/2', '/p/3']);
});

it('keeps the query string of absolute same-host URLs and accepts http too', function (): void {
    config(['pwa.offline.warm.urls' => [
        'http://localhost/orders?tab=open',
        'https://LOCALHOST/reports',
        'https://localhost',
        'ftp://localhost/files',
        'https://evil.test/orders',
        '//evil.test/x',
    ]]);

    expect(app(Pwa::class)->warmUrls())->toBe(['/orders?tab=open', '/reports', '/']);
});

it('lists each page once and stops at offline.warm.max', function (): void {
    config(['pwa.offline.warm.urls' => ['/a', '/b', '/a', '/c', '/d'], 'pwa.offline.warm.max' => 3]);

    expect(app(Pwa::class)->warmUrls())->toBe(['/a', '/b', '/c']);

    config(['pwa.offline.warm.max' => 0]);
    expect(app(Pwa::class)->warmUrls())->toBe([]);
});
