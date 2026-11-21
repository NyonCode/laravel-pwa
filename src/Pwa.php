<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use InvalidArgumentException;
use NyonCode\LaravelPwa\Contracts\TenantResolver;
use NyonCode\LaravelPwa\Events\KillSwitchActivated;
use NyonCode\LaravelPwa\Events\KillSwitchDeactivated;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use NyonCode\LaravelPwa\Support\Value;
use NyonCode\LaravelPwa\Tenancy\Tenant;
use NyonCode\LaravelPwa\Tenancy\TenantManifestResolver;

/**
 * Package entry point: state, client config, kill switch and CSP.
 */
class Pwa
{
    public const KILL_SWITCH_KEY = 'pwa:kill-switch';

    public const DISPLAY_HEADER = 'X-PWA-Display';

    public const DISPLAY_COOKIE = 'pwa_display';

    public const STANDALONE_MODES = ['standalone', 'minimal-ui', 'window-controls-overlay', 'fullscreen', 'tabbed'];

    /** @var list<Closure(Request): iterable<mixed>> */
    protected array $warmers = [];

    /** @var TenantResolver|class-string<TenantResolver>|null */
    protected TenantResolver|string|null $tenantResolver = null;

    public function __construct(
        protected Application $app,
    ) {}

    /**
     * Path tenants on one domain (/t/acme/, /t/globex/): each gets its own worker scope, manifest
     * identity and caches. Overrides config('pwa.tenancy.resolver'); null falls back to it.
     *
     * @param  TenantResolver|class-string<TenantResolver>|null  $resolver
     */
    public function resolveTenantsUsing(TenantResolver|string|null $resolver): static
    {
        $this->tenantResolver = $resolver;

        return $this;
    }

    /**
     * The configured tenant resolver, or null without tenancy.
     */
    public function tenancy(): ?TenantResolver
    {
        $resolver = $this->tenantResolver ?? config('pwa.tenancy.resolver');

        if ($resolver === null || $resolver === '') {
            return null;
        }

        $resolver = is_string($resolver) ? $this->app->make($resolver) : $resolver;

        if (! $resolver instanceof TenantResolver) {
            throw new InvalidArgumentException('The tenant resolver must implement '.TenantResolver::class.', '.get_debug_type($resolver).' given.');
        }

        return $resolver;
    }

    /**
     * The tenant of this request: TenantResolver::current() on pages; on the manifest route the
     * ?tenant= key from the page's manifest link. Resolved once per request.
     */
    public function tenant(?Request $request = null): ?Tenant
    {
        $request ??= request();
        $resolver = $this->tenancy();

        if (! $resolver instanceof TenantResolver) {
            return null;
        }

        $cached = $request->attributes->get('pwa.tenant');

        if ($cached instanceof Tenant || $cached === false) {
            return $cached ?: null;
        }

        $key = $request->query(TenantManifestResolver::PARAMETER);
        $tenant = $request->routeIs('pwa.manifest')
            ? (is_string($key) && $key !== '' ? $resolver->find($key) : null)
            : $resolver->current($request);

        $request->attributes->set('pwa.tenant', $tenant ?? false);

        return $tenant;
    }

    /**
     * Adds pages to warm for the current user, e.g. their latest orders.
     *
     * The callback runs on every keep-alive request (/pwa/session), so keep it cheap.
     *
     * @param  Closure(Request): iterable<mixed>  $callback  Returns paths or same-origin URLs.
     */
    public function warmUsing(Closure $callback): static
    {
        $this->warmers[] = $callback;

        return $this;
    }

    /**
     * Is a warm-up configured (offline.warm.urls or a warmUsing() callback)?
     */
    public function warmsPages(): bool
    {
        return $this->warmers !== [] || (array) config('pwa.offline.warm.urls', []) !== [];
    }

    /**
     * Pages the worker should download for offline use: offline.warm.urls plus warmUsing().
     *
     * Same-origin paths only, never_cache paths removed, at most offline.warm.max.
     *
     * @return list<string>
     */
    public function warmUrls(?Request $request = null): array
    {
        $request ??= request();
        $candidates = (array) config('pwa.offline.warm.urls', []);

        foreach ($this->warmers as $warmer) {
            foreach ($warmer($request) as $url) {
                $candidates[] = $url;
            }
        }

        $never = array_map(Value::string(...), (array) config('pwa.cache.never_cache', []));
        $urls = [];

        foreach ($candidates as $candidate) {
            $url = $this->localPath(Value::string($candidate), $request);

            if ($url !== null && ! Str::is($never, Value::string(parse_url($url, PHP_URL_PATH)))) {
                $urls[$url] = true;
            }
        }

        return array_slice(array_keys($urls), 0, max(0, config()->integer('pwa.offline.warm.max', 20)));
    }

    /**
     * "/path?query" for a path or a URL on the request's host, otherwise null.
     */
    protected function localPath(string $url, Request $request): ?string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || strcasecmp($parts['host'] ?? '', $request->getHost()) !== 0) {
            return null;
        }

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Is the service worker enabled in this installation (config) and the kill switch inactive?
     */
    public function enabled(): bool
    {
        // PWA_ENABLED=1 arrives as the string "1", so this is not config()->boolean().
        return filter_var(config('pwa.enabled', true), FILTER_VALIDATE_BOOL) && ! $this->killed();
    }

    /**
     * Is the kill switch active (pwa:disable)?
     */
    public function killed(): bool
    {
        return (bool) $this->killSwitchStore()->get(self::KILL_SWITCH_KEY, false);
    }

    public function disable(): void
    {
        $this->killSwitchStore()->forever(self::KILL_SWITCH_KEY, true);

        KillSwitchActivated::dispatch();
    }

    public function enable(): void
    {
        $this->killSwitchStore()->forget(self::KILL_SWITCH_KEY);

        KillSwitchDeactivated::dispatch();
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
     * Does the request come from the installed app (standalone, minimal-ui, WCO …)?
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
     * Does the client itself report the installed app (X-PWA-Display header or pwa_display cookie)?
     *
     * Unlike isStandaloneRequest(), it ignores ?source=pwa and the session flag:
     * a link can plant those, so security decisions must not rely on them.
     */
    public function isStandaloneClient(?Request $request = null): bool
    {
        $request ??= request();

        $mode = $request->header(self::DISPLAY_HEADER) ?? $request->cookie(self::DISPLAY_COOKIE);

        return is_string($mode) && in_array($mode, self::STANDALONE_MODES, true);
    }

    /**
     * Anonymous user identity that separates caches in the SW. Never the ID itself.
     */
    public function userHash(?Request $request = null): string
    {
        $request ??= request();
        $user = $request->user();

        if ($user === null) {
            return 'guest';
        }

        return substr(hash_hmac('sha256', Value::string($user->getAuthIdentifier()), config()->string('app.key')), 0, 16);
    }

    /**
     * Config passed to the client script (<x-pwa::head/>).
     *
     * @return array<string, mixed>
     */
    public function clientConfig(): array
    {
        $prefix = '/'.trim(config()->string('pwa.route_prefix', 'pwa'), '/');
        $tenant = $this->tenant();

        return [
            'enabled' => $this->enabled(),
            'killed' => $this->killed(),
            'sw' => config()->string('pwa.sw.url', '/sw.js'),
            // A path tenant registers its own worker for its scope (separate app and caches).
            'scope' => $tenant->scope ?? config()->string('pwa.scope', '/'),
            'startUrl' => $tenant->startUrl ?? config()->string('pwa.start_url', '/'),
            'tenant' => $tenant?->key,
            'session' => [
                'url' => $prefix.'/session',
                'keepalive' => config()->integer('pwa.session.keepalive_minutes', 10),
                'refreshCsrf' => config()->boolean('pwa.session.refresh_csrf', true),
            ],
            'warm' => [
                'enabled' => $this->warmsPages(),
                'interval' => config()->integer('pwa.offline.warm.interval', 60),
            ],
            'ping' => $prefix.'/ping',
            'telemetry' => config('pwa.telemetry.enabled', true) ? $prefix.'/telemetry' : null,
            'install' => [
                'minVisits' => config()->integer('pwa.install.min_visits', 2),
                'dismissDays' => config()->integer('pwa.install.dismiss_days', 30),
                'iosHint' => config()->boolean('pwa.install.ios_hint', true),
            ],
            'nav' => [
                'enabled' => config()->boolean('pwa.desktop_nav.enabled', true),
                'minWidth' => config()->integer('pwa.desktop_nav.min_width', 900),
            ],
            'adapters' => [
                'livewire' => config()->boolean('pwa.adapters.livewire', true),
                'inertia' => config()->boolean('pwa.adapters.inertia', true),
                'reloadOnResume' => config()->boolean('pwa.adapters.reload_on_resume', false),
            ],
            'offlineUrl' => config('pwa.offline.enabled', true) ? config()->string('pwa.offline.route', '/offline') : null,
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
     * SHA-256 hashes of the inline scripts and styles of the prerendered maintenance screen,
     * for a CSP without a nonce (script-src / style-src).
     *
     * @param  view-string  $view
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
