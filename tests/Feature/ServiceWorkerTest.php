<?php

use Illuminate\Support\Facades\File;
use NyonCode\LaravelPwa\Pwa;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;

function viteManifest(array $entries): void
{
    File::ensureDirectoryExists(public_path('build'));
    File::put(public_path('build/manifest.json'), json_encode($entries));
}

it('serves the service worker without caching', function (): void {
    $response = $this->get('/sw.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertHeader('Service-Worker-Allowed', '/');

    expect($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->getContent())->toContain('self.__PWA = ')->toContain("addEventListener('fetch'");
});

it('precaches the offline page and Vite entries', function (): void {
    viteManifest([
        'resources/js/app.js' => ['file' => 'assets/app-abc123.js', 'isEntry' => true, 'css' => ['assets/app-def456.css']],
        'resources/js/lazy.js' => ['file' => 'assets/lazy-999.js'],
    ]);

    expect(app(ServiceWorkerBuilder::class)->precache())->toBe([
        '/offline',
        '/build/assets/app-abc123.js',
        '/build/assets/app-def456.css',
    ]);
});

it('changes version when assets change', function (): void {
    viteManifest(['a.js' => ['file' => 'assets/a-1.js', 'isEntry' => true]]);
    $first = app(ServiceWorkerBuilder::class)->runtimeConfig()['version'];

    viteManifest(['a.js' => ['file' => 'assets/a-2.js', 'isEntry' => true]]);
    $second = app(ServiceWorkerBuilder::class)->runtimeConfig()['version'];

    expect($first)->not->toBe($second);
});

it('imports scripts from sw.import and versions them by content', function (): void {
    config(['pwa.sw.import' => ['/push-sw.js']]);
    File::put(public_path('push-sw.js'), '// v1');

    $builder = app(ServiceWorkerBuilder::class);
    $v1 = $builder->runtimeConfig()['version'];
    expect($builder->build())->toContain('importScripts("/push-sw.js");');

    File::put(public_path('push-sw.js'), '// v2');
    expect($builder->runtimeConfig()['version'])->not->toBe($v1);

    File::delete(public_path('push-sw.js'));
});

it('builds with pwa:build and serves the compiled file', function (): void {
    viteManifest(['a.js' => ['file' => 'assets/a-1.js', 'isEntry' => true]]);

    $this->artisan('pwa:build')->assertSuccessful();

    $builder = app(ServiceWorkerBuilder::class);
    expect($builder->isCompiled())->toBeTrue();

    File::append($builder->compiledPath(), "\n// marker-from-build");
    expect($this->get('/sw.js')->getContent())->toContain('marker-from-build');

    $this->artisan('pwa:clear')->assertSuccessful();
    expect($builder->isCompiled())->toBeFalse();
});

it('serves the kill switch after pwa:disable and restores after pwa:enable', function (): void {
    $this->artisan('pwa:disable', ['--force' => true])->assertSuccessful();

    expect(app(Pwa::class)->killed())->toBeTrue()
        ->and($this->get('/sw.js')->getContent())->toContain('registration.unregister()')->not->toContain('self.__PWA');

    $this->artisan('pwa:enable')->assertSuccessful();

    expect($this->get('/sw.js')->getContent())->toContain('self.__PWA');
});

it('serves the kill switch when the package is disabled (local development)', function (): void {
    config(['pwa.enabled' => false]);

    expect($this->get('/sw.js')->getContent())->toContain('registration.unregister()');
});

it('reads pwa.enabled from env strings like "1" and "0"', function (string $value, bool $enabled): void {
    config(['pwa.enabled' => $value]);

    expect(app(Pwa::class)->enabled())->toBe($enabled);
})->with([['1', true], ['0', false], ['true', true], ['off', false]]);

it('serves the client script with a long cache', function (): void {
    $response = $this->get('/pwa/client.js')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->getContent())->toContain('window.Pwa');
});

it('answers ping without caching', function (): void {
    $this->get('/pwa/ping')->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
});
