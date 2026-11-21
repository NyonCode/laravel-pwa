<?php

use Illuminate\Support\Facades\File;

it('reports problems and fails in ci mode', function () {
    $this->artisan('pwa:doctor')
        ->expectsOutputToContain('Ikona 192×192')
        ->assertSuccessful();

    $this->artisan('pwa:doctor', ['--ci' => true])->assertFailed();
});

it('passes the icon checks once icons exist', function () {
    File::ensureDirectoryExists(public_path('pwa/icons'));
    foreach (['icon-192.png', 'icon-512.png', 'maskable-512.png', 'apple-touch-icon.png'] as $file) {
        File::put(public_path('pwa/icons/'.$file), 'png');
    }

    config(['session.driver' => 'file']);

    $this->artisan('pwa:doctor', ['--ci' => true])->assertSuccessful();
});

it('flags a static public/sw.js that would survive the kill switch', function () {
    File::put(public_path('sw.js'), '// static');

    $this->artisan('pwa:doctor')->expectsOutputToContain('public/sw.js neexistuje');

    File::delete(public_path('sw.js'));
});
