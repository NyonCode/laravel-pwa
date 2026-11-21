<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

it('renders the head component', function (): void {
    $html = Blade::render('<x-pwa::head/>');

    expect($html)
        ->toContain('<link rel="manifest" href="/manifest.webmanifest">')
        ->toContain('id="pwa-config"')
        ->toContain('/pwa/client.js?v=')
        ->toContain('apple-mobile-web-app-capable');

    preg_match('#<script type="application/json" id="pwa-config">(.*?)</script>#s', $html, $m);
    $config = json_decode($m[1], true);

    expect($config['enabled'])->toBeTrue()
        ->and($config['sw'])->toBe('/sw.js')
        ->and($config['session']['url'])->toBe('/pwa/session');
});

it('adds the csp nonce to inline and module scripts', function (): void {
    Vite::useCspNonce('test-nonce-123');

    expect(Blade::render('<x-pwa::head/>'))->toContain('nonce="test-nonce-123"')
        ->and(Blade::render('<x-pwa::nav/>'))->toContain('nonce="test-nonce-123"')
        ->and(view('pwa::offline')->render())->toContain('nonce="test-nonce-123"');
});

it('renders the install button and ios hint hidden by default', function (): void {
    $html = Blade::render('<x-pwa::install class="btn">Instalovat</x-pwa::install>');

    expect($html)->toContain('data-pwa-install hidden')
        ->toContain('class="pwa-install btn"')
        ->toContain('Instalovat')
        ->toContain('data-pwa-ios-hint hidden');
});

it('renders the offline page without external resources', function (): void {
    $html = $this->get('/offline')->assertOk()->getContent();

    expect($html)->not->toMatch('#<(link|script)[^>]+(href|src)=#')
        ->and($html)->toContain('pwa\\/ping');
});

it('translates views', function (string $locale, string $title): void {
    app()->setLocale($locale);

    expect(view('pwa::offline')->render())->toContain($title);
})->with([
    ['cs', 'Jste offline'],
    ['en', 'You are offline'],
]);
