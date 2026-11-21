<?php

use Illuminate\Support\Facades\File;
use NyonCode\LaravelPwa\Facades\Pwa;

const EDGE_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0';
const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
const ANDROID_TABLET_DESKTOP_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const IPAD_SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15';

it('defaults to standalone with minimal-ui override (desktop minimal-ui, mobile standalone)', function (): void {
    $manifest = $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json; charset=utf-8')
        ->json();

    expect($manifest['display'])->toBe('standalone')
        ->and($manifest['display_override'])->toBe(['minimal-ui'])
        ->and($manifest['name'])->toBe('Testovací aplikace')
        ->and($manifest['start_url'])->toBe('/?source=pwa')
        ->and($manifest['id'])->toBe('/');
});

it('is identical for every device with the override strategy (cacheable)', function (): void {
    $edge = $this->withHeaders(['User-Agent' => EDGE_WINDOWS])->get('/manifest.webmanifest');
    $android = $this->withHeaders(['User-Agent' => CHROME_ANDROID])->get('/manifest.webmanifest');

    expect($edge->getContent())->toBe($android->getContent());
    $edge->assertHeaderMissing('Vary');
});

it('decides on the server with the adaptive strategy', function (array $headers, string $expected): void {
    config(['pwa.display.strategy' => 'adaptive']);

    $response = $this->withHeaders($headers)->get('/manifest.webmanifest');

    expect($response->json('display'))->toBe($expected)
        ->and($response->json())->not->toHaveKey('display_override');
    $response->assertHeader('Vary', 'Sec-CH-UA-Platform, User-Agent');
})->with([
    'Edge na Windows (client hints)' => [['User-Agent' => EDGE_WINDOWS, 'Sec-CH-UA-Platform' => '"Windows"'], 'minimal-ui'],
    'Edge na Windows (jen UA)' => [['User-Agent' => EDGE_WINDOWS], 'minimal-ui'],
    'Chrome na Androidu' => [['User-Agent' => CHROME_ANDROID, 'Sec-CH-UA-Platform' => '"Android"'], 'standalone'],
    'Android tablet s desktopovým UA' => [['User-Agent' => ANDROID_TABLET_DESKTOP_UA, 'Sec-CH-UA-Platform' => '"Android"'], 'standalone'],
    'iPad Safari (hlásí se jako Mac)' => [['User-Agent' => IPAD_SAFARI], 'standalone'],
]);

it('supports the fixed strategy', function (): void {
    config(['pwa.display.strategy' => 'fixed', 'pwa.display.value' => 'fullscreen']);

    expect($this->get('/manifest.webmanifest')->json())
        ->display->toBe('fullscreen')
        ->not->toHaveKey('display_override');
});

it('lists only icons that exist', function (): void {
    expect($this->get('/manifest.webmanifest')->json())->not->toHaveKey('icons');

    File::ensureDirectoryExists(public_path('pwa/icons'));
    File::put(public_path('pwa/icons/icon-192.png'), 'png');
    File::put(public_path('pwa/icons/maskable-512.png'), 'png');

    $icons = $this->get('/manifest.webmanifest')->json('icons');

    expect($icons)->toHaveCount(2)
        ->and($icons[0])->toMatchArray(['src' => '/pwa/icons/icon-192.png', 'sizes' => '192x192', 'purpose' => 'any'])
        ->and($icons[1]['purpose'])->toBe('maskable');
});

it('applies fluent configuration from the application', function (): void {
    Pwa::manifest()
        ->shortcut('Nová objednávka', '/orders/create')
        ->shareTarget('/share', 'POST', ['image/*'])
        ->fileHandler('/open', ['text/csv' => ['.csv']])
        ->protocolHandler('web+objednavka', '/orders/%s')
        ->windowControlsOverlay()
        ->launchHandler('focus-existing');

    $manifest = $this->get('/manifest.webmanifest')->json();

    expect($manifest['shortcuts'][0])->toMatchArray(['name' => 'Nová objednávka', 'url' => '/orders/create'])
        ->and($manifest['share_target']['enctype'])->toBe('multipart/form-data')
        ->and($manifest['share_target']['params']['files'][0]['accept'])->toBe(['image/*'])
        ->and($manifest['file_handlers'][0]['action'])->toBe('/open')
        ->and($manifest['protocol_handlers'][0]['protocol'])->toBe('web+objednavka')
        ->and($manifest['display_override'])->toBe(['window-controls-overlay', 'minimal-ui'])
        ->and($manifest['launch_handler'])->toBe(['client_mode' => 'focus-existing']);

    Pwa::manifest()->flush();
});

it('answers 304 for an unchanged manifest', function (): void {
    $etag = $this->get('/manifest.webmanifest')->headers->get('ETag');

    $this->withHeaders(['If-None-Match' => $etag])->get('/manifest.webmanifest')->assertStatus(304);
});

it('sets lang from the application locale', function (): void {
    app()->setLocale('cs');

    expect($this->get('/manifest.webmanifest')->json())
        ->lang->toBe('cs')
        ->dir->toBe('ltr');
});

it('translates name and adds localized variants for configured locales', function (): void {
    $translator = app('translator');
    $translator->addLines(['manifest.name' => 'Orders', 'manifest.short_name' => 'Orders'], 'en', 'pwa');
    $translator->addLines(['manifest.name' => 'Objednávky', 'manifest.description' => 'Správa objednávek'], 'cs', 'pwa');
    config(['pwa.locales' => ['en', 'cs']]);

    $manifest = $this->get('/manifest.webmanifest')->json();

    expect($manifest['name'])->toBe('Orders')
        ->and($manifest['name_localized'])->toBe(['en' => 'Orders', 'cs' => 'Objednávky'])
        ->and($manifest['short_name_localized'])->toBe(['en' => 'Orders'])
        ->and($manifest['description_localized'])->toBe(['cs' => 'Správa objednávek']);
});

it('marks right-to-left locales', function (): void {
    app()->setLocale('ar');

    expect($this->get('/manifest.webmanifest')->json('dir'))->toBe('rtl');
});

it('adds the tabbed mode with its tab strip in front of other overrides', function (): void {
    Pwa::manifest()->tabbed(['home_tab' => ['url' => '/']])->windowControlsOverlay();

    $manifest = $this->get('/manifest.webmanifest')->json();

    expect($manifest['display_override'])->toBe(['window-controls-overlay', 'tabbed', 'minimal-ui'])
        ->and($manifest['tab_strip'])->toBe(['home_tab' => ['url' => '/']]);

    Pwa::manifest()->flush();
});

it('lists only icons that exist, including maskable and SVG', function (): void {
    File::ensureDirectoryExists(public_path('pwa/icons'));
    foreach (['icon-192.png', 'maskable-512.png', 'icon.svg'] as $file) {
        File::put(public_path("pwa/icons/{$file}"), 'x');
    }

    $icons = collect($this->get('/manifest.webmanifest')->json('icons'));

    expect($icons->pluck('src')->all())->toBe(['/pwa/icons/icon-192.png', '/pwa/icons/maskable-512.png', '/pwa/icons/icon.svg'])
        ->and($icons->firstWhere('purpose', 'maskable')['sizes'])->toBe('512x512')
        ->and($icons->last()['type'])->toBe('image/svg+xml');
});

it('adds screenshots, categories and shortcut icon sizes through the builder', function (): void {
    config()->set('pwa.categories', ['business']);

    $manifest = Pwa::manifest()
        ->screenshot('/shots/wide.png', '1280x720', 'wide', 'Dashboard')
        ->category('productivity', 'business')
        ->shortcut('Orders', '/orders', '/icons/orders.png', iconSizes: '192x192')
        ->toArray();

    expect($manifest['screenshots'])->toBe([['src' => '/shots/wide.png', 'sizes' => '1280x720', 'type' => 'image/png', 'form_factor' => 'wide', 'label' => 'Dashboard']])
        ->and($manifest['categories'])->toBe(['business', 'productivity'])
        ->and($manifest['shortcuts'][0]['icons'])->toBe([['src' => '/icons/orders.png', 'sizes' => '192x192']]);

    Pwa::manifest()->flush();
});
