<?php

use Illuminate\Support\Facades\File;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;

// A Vite build with a shared vendor chunk, a lazy page and a font.
beforeEach(function (): void {
    File::ensureDirectoryExists(public_path('build/assets'));
    File::put(public_path('build/manifest.json'), json_encode([
        'resources/js/app.js' => [
            'file' => 'assets/app-1.js', 'isEntry' => true, 'css' => ['assets/app-1.css'],
            'imports' => ['_vendor-1.js'], 'dynamicImports' => ['resources/js/lazy.js'], 'assets' => ['assets/inter-1.woff2'],
        ],
        '_vendor-1.js' => ['file' => 'assets/vendor-1.js', 'imports' => ['_shared-1.js'], 'css' => ['assets/vendor-1.css']],
        '_shared-1.js' => ['file' => 'assets/shared-1.js', 'imports' => ['_vendor-1.js']], // cycle
        'resources/js/lazy.js' => ['file' => 'assets/lazy-1.js', 'isDynamicEntry' => true, 'assets' => ['assets/video-1.mp4']],
    ]));
});

afterEach(fn () => File::deleteDirectory(public_path('build')));

it('precaches entries with their static imports, CSS and assets by default', function (): void {
    expect(app(ServiceWorkerBuilder::class)->viteFiles())->toBe([
        '/build/assets/app-1.css',
        '/build/assets/app-1.js',
        '/build/assets/inter-1.woff2',
        '/build/assets/shared-1.js',
        '/build/assets/vendor-1.css',
        '/build/assets/vendor-1.js',
    ]);
});

it('precaches only entries and their CSS with precache_vite = entries', function (): void {
    config()->set('pwa.sw.precache_vite', 'entries');

    expect(app(ServiceWorkerBuilder::class)->viteFiles())->toBe(['/build/assets/app-1.css', '/build/assets/app-1.js']);
});

it('precaches every chunk with precache_vite = all, and nothing with false', function (): void {
    config()->set('pwa.sw.precache_vite', 'all');
    expect(app(ServiceWorkerBuilder::class)->viteFiles())->toContain('/build/assets/lazy-1.js', '/build/assets/video-1.mp4');

    config()->set('pwa.sw.precache_vite', false);
    expect(app(ServiceWorkerBuilder::class)->viteFiles())->toBe([]);
});

it('leaves out files matching precache_exclude', function (): void {
    config()->set('pwa.sw.precache_vite', 'all');
    config()->set('pwa.sw.precache_exclude', ['assets/*.mp4', 'assets/*.woff2']);

    expect(app(ServiceWorkerBuilder::class)->viteFiles())
        ->not->toContain('/build/assets/video-1.mp4')
        ->not->toContain('/build/assets/inter-1.woff2')
        ->toContain('/build/assets/lazy-1.js');
});

it('rejects an unknown precache mode', function (): void {
    config()->set('pwa.sw.precache_vite', 'everything');

    app(ServiceWorkerBuilder::class)->viteFiles();
})->throws(RuntimeException::class, 'precache_vite');

it('measures the precache and warns about a large one', function (): void {
    File::put(public_path('build/assets/app-1.js'), str_repeat('x', 3 * 1024));
    File::put(public_path('build/assets/vendor-1.js'), str_repeat('x', 2 * 1024));
    config()->set('pwa.sw.precache_warn_kb', 4);

    expect(app(ServiceWorkerBuilder::class)->precacheBytes())->toBeGreaterThanOrEqual(5 * 1024);

    $this->artisan('pwa:build')
        ->expectsOutputToContain('KB)')
        ->expectsOutputToContain('pwa.sw.precache_warn_kb')
        ->assertSuccessful();

    app(ServiceWorkerBuilder::class)->clear();
});

it('reports the precache size in pwa:doctor', function (): void {
    config()->set('pwa.sw.precache_warn_kb', 0);

    $this->artisan('pwa:doctor')->expectsOutputToContain('Precache size');
});
