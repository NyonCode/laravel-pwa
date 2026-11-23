<?php

use Illuminate\Support\Facades\Vite;
use NyonCode\LaravelPwa\Pwa;

// The client reads its whole configuration from #pwa-config: every default is part of the contract.

it('sends the documented defaults to the client', function (): void {
    $config = app(Pwa::class)->clientConfig();

    expect($config)->toMatchArray([
        'enabled' => true,
        'killed' => false,
        'sw' => '/sw.js',
        'scope' => '/',
        'session' => ['url' => '/pwa/session', 'keepalive' => 10, 'refreshCsrf' => true],
        'ping' => '/pwa/ping',
        'telemetry' => '/pwa/telemetry',
        'install' => ['minVisits' => 2, 'dismissDays' => 30, 'iosHint' => true],
        'updates' => ['bar' => true],
        'nav' => ['enabled' => true, 'minWidth' => 900],
        'adapters' => ['livewire' => true, 'inertia' => true, 'reloadOnResume' => false],
        'offlineUrl' => '/offline',
        'push' => null,
        'i18n' => [
            'offline' => __('pwa::messages.offline_bar'),
            'notSent' => __('pwa::messages.offline_not_sent'),
            'replayFailed' => __('pwa::messages.replay_failed'),
            'queueFull' => __('pwa::messages.queue_full'),
            'maintenance' => __('pwa::messages.maintenance_bar'),
            'update' => __('pwa::messages.update_available'),
            'reload' => __('pwa::messages.reload'),
        ],
    ])->and($config['warm'])->toBe(['enabled' => false, 'interval' => 60]);
});

it('passes changed settings through', function (): void {
    config()->set([
        'pwa.route_prefix' => '/app-pwa/',
        'pwa.session.keepalive_minutes' => 3,
        'pwa.session.refresh_csrf' => false,
        'pwa.offline.warm.interval' => 15,
        'pwa.install' => ['min_visits' => 1, 'dismiss_days' => 7, 'ios_hint' => false],
        'pwa.desktop_nav' => ['enabled' => false, 'min_width' => 1200],
        'pwa.adapters' => ['livewire' => false, 'inertia' => false, 'reload_on_resume' => true],
        'pwa.telemetry.enabled' => false,
        'pwa.offline.enabled' => false,
    ]);

    $config = app(Pwa::class)->clientConfig();

    expect($config['session'])->toBe(['url' => '/app-pwa/session', 'keepalive' => 3, 'refreshCsrf' => false])
        ->and($config['ping'])->toBe('/app-pwa/ping')
        ->and($config['telemetry'])->toBeNull()
        ->and($config['offlineUrl'])->toBeNull()
        ->and($config['warm']['interval'])->toBe(15)
        ->and($config['install'])->toBe(['minVisits' => 1, 'dismissDays' => 7, 'iosHint' => false])
        ->and($config['nav'])->toBe(['enabled' => false, 'minWidth' => 1200])
        ->and($config['adapters'])->toBe(['livewire' => false, 'inertia' => false, 'reloadOnResume' => true]);
});

it('does not promise to send changes later without the offline queue', function (): void {
    config()->set('pwa.offline.queue', false);

    expect(app(Pwa::class)->clientConfig()['i18n']['offline'])->toBe(__('pwa::messages.offline_bar_no_queue'))
        ->and(__('pwa::messages.offline_bar_no_queue'))->toBe('You are offline.');
});

it('reports the kill switch to the client', function (): void {
    app(Pwa::class)->disable();

    expect(app(Pwa::class)->clientConfig())->toMatchArray(['enabled' => false, 'killed' => true]);

    app(Pwa::class)->enable();
});

it('turns push on only with a public key', function (): void {
    config()->set('pwa.push.enabled', true);

    config()->set('pwa.push.vapid.public_key', '');
    expect(app(Pwa::class)->pushEnabled())->toBeFalse();

    config()->set('pwa.push.vapid.public_key', 'BKey');
    expect(app(Pwa::class)->pushEnabled())->toBeTrue();

    config()->set('pwa.push.enabled', false);
    expect(app(Pwa::class)->pushEnabled())->toBeFalse();
});

it('returns no nonce for an empty one', function (): void {
    Vite::useCspNonce('');
    expect(app(Pwa::class)->cspNonce())->toBeNull();

    Vite::useCspNonce('abc');
    expect(app(Pwa::class)->cspNonce())->toBe('abc');
});

it('hashes user ids to 16 hex characters with the app key', function (): void {
    $hash = app(Pwa::class)->identityHash(42);

    expect($hash)->toMatch('/^[a-f0-9]{16}$/')
        ->and($hash)->toBe(substr(hash_hmac('sha256', '42', config('app.key')), 0, 16))
        ->and(app(Pwa::class)->identityHash(43))->not->toBe($hash);
});

it('versions the client URL with 12 characters of its hash', function (): void {
    $file = dirname(__DIR__, 2).'/resources/js/pwa.js';

    expect(app(Pwa::class)->clientUrl())->toBe('/pwa/client.js?v='.substr(hash_file('sha256', $file), 0, 12));
});

it('hashes the inline scripts and styles of the maintenance screen', function (): void {
    $hashes = app(Pwa::class)->cspHashes();
    $html = view('pwa::maintenance', ['retryAfter' => config('pwa.maintenance.retry')])->render();
    preg_match('#<script[^>]*>(.*?)</script>#s', $html, $script);

    expect($hashes['script-src'])->toContain("'sha256-".base64_encode(hash('sha256', $script[1], true))."'")
        ->and($hashes['style-src'])->not->toBeEmpty()
        ->and($hashes['style-src'][0])->toStartWith("'sha256-")->toEndWith("'");
});

it('uses the configured cache store for the kill switch', function (): void {
    config()->set('cache.stores.pwa-flags', ['driver' => 'array']);
    config()->set('pwa.sw.kill_switch_store', 'pwa-flags');

    app(Pwa::class)->disable();

    expect(cache()->store('pwa-flags')->get(Pwa::KILL_SWITCH_KEY))->toBeTrue()
        ->and(app(Pwa::class)->killed())->toBeTrue();

    config()->set('pwa.sw.kill_switch_store', '');
    expect(app(Pwa::class)->killed())->toBeFalse(); // the default store has no flag
});
