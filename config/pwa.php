<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | The service worker is off by default in the local environment so it does
    | not fight Vite HMR. While disabled, the client unregisters an old worker.
    | The manifest and the components work either way.
    |
    */

    'enabled' => env('PWA_ENABLED', env('APP_ENV') !== 'local'),

    /*
    |--------------------------------------------------------------------------
    | App identity
    |--------------------------------------------------------------------------
    */

    'name' => env('PWA_NAME', env('APP_NAME', 'Laravel')),

    'short_name' => env('PWA_SHORT_NAME'),

    'description' => env('PWA_DESCRIPTION'),

    'id' => '/',

    'start_url' => '/?source=pwa',

    'scope' => '/',

    /*
    |--------------------------------------------------------------------------
    | Display mode
    |--------------------------------------------------------------------------
    |
    | override: display = value, display_override = override (no device
    |           detection: Edge/Chrome on desktop get minimal-ui, phones,
    |           tablets and Safari get standalone)
    | adaptive: the server decides from Sec-CH-UA-Platform / User-Agent
    | fixed:    only display = value
    |
    */

    'display' => [
        'strategy' => env('PWA_DISPLAY_STRATEGY', 'override'),
        'value' => 'standalone',
        'override' => ['minimal-ui'],
        'desktop' => 'minimal-ui',   // adaptive strategy only
    ],

    'orientation' => null,

    /*
    |--------------------------------------------------------------------------
    | Back / Reload buttons on desktop (<x-pwa::nav/>)
    |--------------------------------------------------------------------------
    */

    'desktop_nav' => [
        'enabled' => true,
        'min_width' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Colours and icons
    |--------------------------------------------------------------------------
    */

    'theme' => [
        'light' => '#0d6efd',
        'dark' => '#0b1220',
        'accent_dark' => '#5b9dff',
        'background' => '#ffffff',
    ],

    'icons' => [
        'source' => null,               // SVG/PNG for pwa:icons, e.g. resource_path('pwa/icon.png')
        'path' => 'pwa/icons',          // directory inside public/
        'maskable_padding' => 0.1,
        'sizes' => [192, 512],
        // iOS startup images (php artisan pwa:icons --splash), linked by <x-pwa::head/>.
        'splash' => [
            'background' => null,       // null = theme.background
            'dark_background' => null,  // e.g. '#0b1220' adds prefers-color-scheme: dark variants
            'icon_size' => 0.3,         // icon edge as a fraction of the shorter screen edge
            'orientations' => ['portrait', 'landscape'],
            'devices' => null,          // null = all, or keys of NyonCode\LaravelPwa\Icons\AppleDevices::SCREENS
        ],
    ],

    // Richer install dialog on Android and desktop Chrome: at least one 'wide' and one 'narrow'.
    // ['src' => '/pwa/screenshots/desktop.png', 'sizes' => '1280x800', 'type' => 'image/png', 'form_factor' => 'wide']
    'screenshots' => [],

    /*
    |--------------------------------------------------------------------------
    | Operating system integration
    |--------------------------------------------------------------------------
    */

    'categories' => [],

    'shortcuts' => [
        // ['name' => 'New order', 'url' => '/orders/create', 'icon' => '/pwa/icons/shortcut-new.png'],
    ],

    'share_target' => null,

    'file_handlers' => [],

    'protocol_handlers' => [],

    'launch_handler' => ['client_mode' => ['navigate-existing', 'auto']],

    'scope_extensions' => [],

    'locales' => [],                    // extra languages for the localised manifest, e.g. ['en', 'de']

    /*
    |--------------------------------------------------------------------------
    | Manifest resolvers
    |--------------------------------------------------------------------------
    |
    | Classes implementing NyonCode\LaravelPwa\Contracts\ManifestResolver,
    | applied to every manifest request (tenant branding, per-locale names …).
    | Closures: Pwa::manifest()->resolveUsing(fn ($request, $manifest) => …).
    |
    */

    'manifest' => [
        'resolvers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Service worker
    |--------------------------------------------------------------------------
    */

    'sw' => [
        'url' => '/sw.js',
        // Scripts loaded BEFORE the runtime with importScripts (e.g. a push system's listeners).
        'import' => [],
        // Scripts loaded AFTER the runtime; they can use self.PwaWorker (routes, strategies, cache names).
        'extend' => [],
        // Vite manifest used for the precache.
        'vite_manifest' => 'build/manifest.json',
        'build_directory' => 'build',
        // Where pwa:build writes the assembled worker.
        'compiled_path' => 'bootstrap/cache/pwa',
        // Cache store for the kill switch flag (null = default store).
        'kill_switch_store' => env('PWA_KILL_SWITCH_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache strategies
    |--------------------------------------------------------------------------
    |
    | HTML pages are cached only with the X-PWA-Cache: allow header (middleware
    | pwa.cache). Paths in never_cache always go to the network. Rules apply to
    | other same-origin GET requests; the first matching rule wins. Caches are
    | per user (purged on logout) unless a rule sets 'shared' => true.
    |
    | Strategies: network-first, cache-first, stale-while-revalidate,
    | network-only, cache-only.
    |
    */

    'cache' => [
        'navigation_timeout' => 3,
        'rules' => [
            // ['match' => '/api/catalog*', 'strategy' => 'stale-while-revalidate', 'cache' => 'catalog', 'max_entries' => 200, 'max_age' => 86400],
            // ['match' => '/avatars/*', 'destination' => 'image', 'strategy' => 'cache-first', 'max_age' => 604800],
            // ['match' => '/api/stock', 'strategy' => 'network-first', 'timeout' => 2],
        ],
        'api' => [],                    // shorthand: network-first rules, e.g. ['/api/catalog*']
        'images' => ['enabled' => true, 'max_entries' => 120, 'max_age' => 60 * 60 * 24 * 30],
        'never_cache' => ['/login', '/logout', '/register', '/broadcasting/*', '/livewire/*', '/sanctum/*', '/pwa/*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline
    |--------------------------------------------------------------------------
    */

    'offline' => [
        'enabled' => true,
        'route' => '/offline',
        'precache' => [],
        'queue' => true,                // queue POST/PUT/PATCH/DELETE while the network is down
    ],

    /*
    |--------------------------------------------------------------------------
    | Session (persistent login in the installed app)
    |--------------------------------------------------------------------------
    */

    'session' => [
        'standalone_remember' => true,
        'keepalive_minutes' => 10,
        'refresh_csrf' => true,
        'remember_field' => 'remember',
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode (pwa:down)
    |--------------------------------------------------------------------------
    */

    'maintenance' => [
        'message' => null,
        'until' => null,
        'retry' => 60,
        'refresh' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Install prompt
    |--------------------------------------------------------------------------
    */

    'install' => [
        'min_visits' => 2,
        'dismiss_days' => 30,
        'ios_hint' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry (service worker errors)
    |--------------------------------------------------------------------------
    */

    'telemetry' => [
        'enabled' => true,
        'log' => true,                  // log TelemetryReceived events (off when you handle them yourself)
        'channel' => env('PWA_LOG_CHANNEL'),
        'max_events' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Livewire and Inertia
    |--------------------------------------------------------------------------
    */

    'adapters' => [
        'livewire' => true,
        'inertia' => true,
        'reload_on_resume' => false,
    ],

    'route_prefix' => 'pwa',
];
