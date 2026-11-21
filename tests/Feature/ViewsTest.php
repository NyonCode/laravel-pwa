<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use NyonCode\LaravelPwa\Pwa;

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

it('renders the iOS install instructions as steps, a hint or not at all', function (): void {
    expect(Blade::render('<x-pwa::install/>'))
        ->toContain('pwa-ios-hint--sheet')
        ->toContain('Add to Home Screen')
        ->toContain('<svg class="pwa-ios-hint__icon"')
        ->and(Blade::render('<x-pwa::install ios="hint"/>'))
        ->toContain('pwa-ios-hint--hint')
        ->toContain(__('pwa::messages.ios_hint'))
        ->and(Blade::render('<x-pwa::install ios="none"/>'))
        ->not->toContain('data-pwa-ios-hint');
});

it('renders the update toast hidden, with its actions', function (): void {
    $html = Blade::render('<x-pwa::update-toast class="toast">Updated!<x-slot:apply>Go</x-slot:apply></x-pwa::update-toast>');

    expect($html)->toContain('data-pwa-update hidden')
        ->toContain('class="pwa-ui pwa-toast toast"')
        ->toContain('Updated!')
        ->toContain('data-pwa-update-apply')
        ->toContain('>Go</button>')
        ->toContain('data-pwa-update-dismiss')
        ->and(Blade::render('<x-pwa::update-toast :dismissible="false"/>'))
        ->not->toContain('data-pwa-update-dismiss');
});

it('renders the push toggle with a label for each state', function (): void {
    $html = Blade::render('<x-pwa::push-toggle class="btn"><x-slot:enable>Notify me</x-slot:enable></x-pwa::push-toggle>');

    expect($html)->toContain('data-pwa-push hidden')
        ->toContain('data-pwa-push-toggle')
        ->toContain('class="pwa-push__toggle btn"')
        ->toContain('Notify me')
        ->toContain('data-pwa-push-label="subscribed"')
        ->toContain(__('pwa::messages.push_denied'))
        ->toContain(__('pwa::messages.push_install_required'));
});

it('prints the base styles once and lets them be turned off', function (): void {
    Vite::useCspNonce('ui-nonce');

    $html = Blade::render('<x-pwa::install/><x-pwa::update-toast/><x-pwa::push-toggle/>');

    expect(substr_count($html, '<style'))->toBe(1)
        ->and($html)->toContain('nonce="ui-nonce"')
        ->and(Blade::render('<x-pwa::update-toast :styles="false"/>'))->not->toContain('<style');
});

it('translates the components', function (): void {
    app()->setLocale('cs');

    expect(Blade::render('<x-pwa::update-toast/><x-pwa::push-toggle/>'))
        ->toContain('Aktualizovat')
        ->toContain('Zapnout oznámení');
});

it('passes the update bar setting to the client', function (): void {
    config()->set('pwa.updates.bar', false);

    expect(app(Pwa::class)->clientConfig()['updates'])->toBe(['bar' => false]);
});
