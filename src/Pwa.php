<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;

/**
 * Vstupní bod balíčku: stav, konfigurace pro klienta, nouzové vypnutí a CSP.
 */
class Pwa
{
    public const KILL_SWITCH_KEY = 'pwa:kill-switch';

    public const DISPLAY_HEADER = 'X-PWA-Display';

    public const DISPLAY_COOKIE = 'pwa_display';

    public const STANDALONE_MODES = ['standalone', 'minimal-ui', 'window-controls-overlay', 'fullscreen', 'tabbed'];

    public function __construct(
        protected Application $app,
    ) {}

    /**
     * Je service worker v této instalaci zapnutý (config) a není nouzově vypnutý?
     */
    public function enabled(): bool
    {
        return (bool) config('pwa.enabled', true) && ! $this->killed();
    }

    /**
     * Je aktivní nouzové vypnutí (pwa:disable)?
     */
    public function killed(): bool
    {
        return (bool) $this->killSwitchStore()->get(self::KILL_SWITCH_KEY, false);
    }

    public function disable(): void
    {
        $this->killSwitchStore()->forever(self::KILL_SWITCH_KEY, true);
    }

    public function enable(): void
    {
        $this->killSwitchStore()->forget(self::KILL_SWITCH_KEY);
    }

    public function manifest(): ManifestBuilder
    {
        return $this->app->make(ManifestBuilder::class);
    }

    public function serviceWorker(): ServiceWorkerBuilder
    {
        return $this->app->make(ServiceWorkerBuilder::class);
    }

    /**
     * Běží požadavek z nainstalované aplikace (standalone, minimal-ui, WCO …)?
     */
    public function isStandaloneRequest(?Request $request = null): bool
    {
        $request ??= request();

        if ($this->isStandaloneClient($request)) {
            return true;
        }

        if ($request->query('source') === 'pwa') {
            return true;
        }

        return $request->hasSession() && (bool) $request->session()->get('pwa.standalone', false);
    }

    /**
     * Hlásí nainstalovanou aplikaci sám klient (hlavička X-PWA-Display nebo cookie pwa_display)?
     *
     * Na rozdíl od isStandaloneRequest() nebere v úvahu ?source=pwa ani označení session:
     * ty se dají podstrčit odkazem, proto se podle nich nesmí rozhodovat o bezpečnosti.
     */
    public function isStandaloneClient(?Request $request = null): bool
    {
        $request ??= request();

        $mode = $request->header(self::DISPLAY_HEADER) ?? $request->cookie(self::DISPLAY_COOKIE);

        return is_string($mode) && in_array($mode, self::STANDALONE_MODES, true);
    }

    /**
     * Anonymní identita uživatele pro oddělení cache ve SW. Nikdy ne ID samotné.
     */
    public function userHash(?Request $request = null): string
    {
        $request ??= request();
        $user = $request->user();

        if ($user === null) {
            return 'guest';
        }

        return substr(hash_hmac('sha256', (string) $user->getAuthIdentifier(), (string) config('app.key')), 0, 16);
    }

    /**
     * Konfigurace předaná klientskému skriptu (<x-pwa::head/>).
     *
     * @return array<string, mixed>
     */
    public function clientConfig(): array
    {
        $prefix = '/'.trim((string) config('pwa.route_prefix', 'pwa'), '/');

        return [
            'enabled' => $this->enabled(),
            'killed' => $this->killed(),
            'sw' => (string) config('pwa.sw.url', '/sw.js'),
            'scope' => (string) config('pwa.scope', '/'),
            'startUrl' => (string) config('pwa.start_url', '/'),
            'session' => [
                'url' => $prefix.'/session',
                'keepalive' => (int) config('pwa.session.keepalive_minutes', 10),
                'refreshCsrf' => (bool) config('pwa.session.refresh_csrf', true),
            ],
            'ping' => $prefix.'/ping',
            'telemetry' => config('pwa.telemetry.enabled', true) ? $prefix.'/telemetry' : null,
            'install' => [
                'minVisits' => (int) config('pwa.install.min_visits', 2),
                'dismissDays' => (int) config('pwa.install.dismiss_days', 30),
                'iosHint' => (bool) config('pwa.install.ios_hint', true),
            ],
            'nav' => [
                'enabled' => (bool) config('pwa.desktop_nav.enabled', true),
                'minWidth' => (int) config('pwa.desktop_nav.min_width', 900),
            ],
            'adapters' => [
                'livewire' => (bool) config('pwa.adapters.livewire', true),
                'inertia' => (bool) config('pwa.adapters.inertia', true),
                'reloadOnResume' => (bool) config('pwa.adapters.reload_on_resume', false),
            ],
            'offlineUrl' => config('pwa.offline.enabled', true) ? (string) config('pwa.offline.route', '/offline') : null,
            'i18n' => [
                'offline' => __('pwa::messages.offline_bar'),
                'maintenance' => __('pwa::messages.maintenance_bar'),
                'update' => __('pwa::messages.update_available'),
                'reload' => __('pwa::messages.reload'),
            ],
        ];
    }

    public function cspNonce(): ?string
    {
        $nonce = Vite::cspNonce();

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    /**
     * SHA-256 hashe inline skriptů a stylů předrenderované obrazovky údržby,
     * pro CSP bez nonce (script-src / style-src).
     *
     * @return array{script-src: list<string>, style-src: list<string>}
     */
    public function cspHashes(string $view = 'pwa::maintenance'): array
    {
        $html = view($view, ['retryAfter' => config('pwa.maintenance.retry')])->render();

        $hashes = ['script-src' => [], 'style-src' => []];

        foreach (['script' => 'script-src', 'style' => 'style-src'] as $tag => $directive) {
            preg_match_all('#<'.$tag.'(?:\s[^>]*)?>(.*?)</'.$tag.'>#s', $html, $matches);

            foreach ($matches[1] as $body) {
                $hashes[$directive][] = "'sha256-".base64_encode(hash('sha256', $body, true))."'";
            }
        }

        return $hashes;
    }

    protected function killSwitchStore(): CacheRepository
    {
        $store = config('pwa.sw.kill_switch_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
