<?php

use Illuminate\Support\Facades\File;

it('reports problems and fails in ci mode', function (): void {
    $this->artisan('pwa:doctor')
        ->expectsOutputToContain('Ikona 192×192')
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

it('flags a static public/sw.js that would survive the kill switch', function (): void {
    File::put(public_path('sw.js'), '// static');

    $this->artisan('pwa:doctor')->expectsOutputToContain('public/sw.js neexistuje');

    File::delete(public_path('sw.js'));
});

it('flags a built worker that no longer matches the config', function (): void {
    $this->artisan('pwa:build')->assertSuccessful();
    config(['pwa.cache.navigation_timeout' => 9]);

    $this->artisan('pwa:doctor')->expectsOutputToContain('Sestavený SW je aktuální')->assertSuccessful();
    $this->artisan('pwa:doctor', ['--ci' => true])->assertFailed();
});

it('checks sw.import files', function (): void {
    File::put(public_path('push-sw.js'), '// push');
    config(['pwa.sw.import' => ['/push-sw.js', '/missing.js', 'https://cdn.example.com/x.js']]);

    $this->artisan('pwa:doctor')
        ->expectsOutputToContain('sw.import /push-sw.js existuje')
        ->expectsOutputToContain('sw.import /missing.js existuje')
        ->expectsOutputToContain('je z jiné domény')
        ->assertSuccessful();

    File::delete(public_path('push-sw.js'));
});

it('shows PWA details in php artisan about', function (): void {
    $this->artisan('about')
        ->expectsOutputToContain('Laravel PWA')
        ->expectsOutputToContain('Kill switch')
        ->assertSuccessful();
});
