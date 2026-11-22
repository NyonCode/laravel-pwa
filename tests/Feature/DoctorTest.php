<?php

use Illuminate\Support\Facades\File;

it('reports problems and fails in ci mode', function (): void {
    $this->artisan('pwa:doctor')
        ->expectsOutputToContain('Icon 192×192')
        ->assertSuccessful();

    $this->artisan('pwa:doctor', ['--ci' => true])->assertFailed();
});

it('passes the icon checks once icons exist', function (): void {
    File::ensureDirectoryExists(public_path('pwa/icons'));
    foreach (['icon-192.png', 'icon-512.png', 'maskable-512.png', 'apple-touch-icon.png'] as $file) {
        File::put(public_path('pwa/icons/'.$file), 'png');
    }

    config(['session.driver' => 'file']);

    $this->artisan('pwa:doctor', ['--ci' => true])->assertSuccessful();
});

it('checks screenshots against their files', function (): void {
    File::ensureDirectoryExists(public_path('pwa/screenshots'));
    imagepng(imagecreatetruecolor(40, 20), public_path('pwa/screenshots/wide.png'));

    config(['pwa.screenshots' => [
        ['src' => '/pwa/screenshots/wide.png', 'sizes' => '40x20', 'type' => 'image/png', 'form_factor' => 'wide'],
        ['src' => '/pwa/screenshots/narrow.png', 'sizes' => '20x40', 'type' => 'image/png', 'form_factor' => 'narrow'],
        ['src' => '/pwa/screenshots/wide.png', 'sizes' => '1280x800', 'type' => 'image/jpeg'],
    ]]);

    $this->artisan('pwa:doctor')
        ->expectsOutputToContain('sizes "40x20" match the file (40x20)')
        ->expectsOutputToContain('Screenshot /pwa/screenshots/narrow.png exists')
        ->expectsOutputToContain('sizes "1280x800" match the file (40x20)')
        ->expectsOutputToContain('type "image/jpeg" matches the file (image/png)')
        ->expectsOutputToContain('form_factor wide and narrow')
        ->assertSuccessful();

    File::deleteDirectory(public_path('pwa/screenshots'));
});

it('flags a static public/sw.js that would survive the kill switch', function (): void {
    File::put(public_path('sw.js'), '// static');

    $this->artisan('pwa:doctor')->expectsOutputToContain('public/sw.js does not exist');

    File::delete(public_path('sw.js'));
});

it('flags a built worker that no longer matches the config', function (): void {
    $this->artisan('pwa:build')->assertSuccessful();
    config(['pwa.cache.navigation_timeout' => 9]);

    $this->artisan('pwa:doctor')->expectsOutputToContain('Built service worker is up to date')->assertSuccessful();
    $this->artisan('pwa:doctor', ['--ci' => true])->assertFailed();
});

it('checks sw.import files', function (): void {
    File::put(public_path('push-sw.js'), '// push');
    config(['pwa.sw.import' => ['/push-sw.js', '/missing.js', 'https://cdn.example.com/x.js']]);

    $this->artisan('pwa:doctor')
        ->expectsOutputToContain('sw.import /push-sw.js exists')
        ->expectsOutputToContain('sw.import /missing.js exists')
        ->expectsOutputToContain('is on another domain')
        ->assertSuccessful();

    File::delete(public_path('push-sw.js'));
});

it('shows PWA details in php artisan about', function (): void {
    $this->artisan('about')
        ->expectsOutputToContain('Laravel PWA')
        ->expectsOutputToContain('Kill switch')
        ->assertSuccessful();
});

it('explains that the service worker is off locally by default', function (): void {
    config(['pwa.enabled' => false]);
    app()->detectEnvironment(fn (): string => 'local');

    $this->artisan('pwa:doctor')
        ->expectsOutputToContain('off in the local environment by default (PWA_ENABLED=true to try it)')
        ->assertSuccessful();

    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('pwa:doctor')
        ->expectsOutputToContain('Service worker off (pwa.enabled, PWA_ENABLED)')
        ->assertSuccessful();
});
