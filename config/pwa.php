<?php

declare(strict_types=1);

use NyonCode\LaravelPwa\Push\PushSubscription;

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
    | Path tenants on one domain
    |--------------------------------------------------------------------------
    |
    | A class implementing NyonCode\LaravelPwa\Contracts\TenantResolver. Each
    | tenant (/t/acme/, /t/globex/) then installs as its own app: own worker
    | scope, manifest id and start_url, own caches. Tenants on subdomains need
    | nothing here, the browser already keeps origins apart.
    |
    */

    'tenancy' => [
        'resolver' => null,
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
        // What to precache from it: 'imports' (entries with their static imports, CSS and assets),
        // 'entries' (entries and their CSS), 'all' (every chunk, also dynamic imports) or false.
        'precache_vite' => 'imports',
        // Files to leave out, relative to the build directory (Str::is patterns, e.g. 'assets/*.mp4').
        'precache_exclude' => [],
        // pwa:build and pwa:doctor warn above this size (KB); 0 turns the warning off.
        'precache_warn_kb' => 5120,
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
        'never_cache' => ['/login', '/logout', '/register', '/broadcasting/*', '/livewire/*', '/livewire-*', '/sanctum/*', '/pwa/*'],
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
        // Pages the worker downloads ahead of time, so they open offline without a prior visit.
        // Only pages with the pwa.cache middleware are stored. Per-user lists: Pwa::warmUsing().
        'warm' => [
            'urls' => [],               // e.g. ['/dashboard', '/orders']
            'max' => 20,                // at most this many URLs per warm-up
            'interval' => 60,           // minutes between automatic warm-ups (login always warms)
        ],
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
    | Updates
    |--------------------------------------------------------------------------
    |
    | A new service worker waits until the user accepts it. The built-in bar asks
    | for that; it is skipped on pages with <x-pwa::update-toast/> (or any
    | [data-pwa-update] element) and when 'bar' is false (your own UI listens
    | to pwa:update-available and calls Pwa.applyUpdate()).
    |
    */

    'updates' => [
        'bar' => true,
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
    | Web push notifications (optional module)
    |--------------------------------------------------------------------------
    |
    | Off by default. Needs minishlink/web-push, VAPID keys (php artisan
    | pwa:push:vapid) and the subscriptions table (php artisan vendor:publish
    | --tag="pwa::migrations"). Notify through WebPushChannel; the worker shows
    | the notifications unless handle_in_worker is false (own sw.import code).
    |
    */

    'push' => [
        'enabled' => env('PWA_PUSH_ENABLED', false),
        'vapid' => [
            'subject' => env('PWA_VAPID_SUBJECT', env('APP_URL')),   // mailto: or https: contact
            'public_key' => env('PWA_VAPID_PUBLIC_KEY'),
            'private_key' => env('PWA_VAPID_PRIVATE_KEY'),
        ],
        'model' => PushSubscription::class,
        'table' => 'pwa_push_subscriptions',
        'middleware' => ['auth'],       // subscription routes, after the web group
        'max_subscriptions' => 10,      // devices per owner; the least recently used goes first
        // pwa:push:prune (and model:prune) deletes subscriptions past their expiration time and those
        // unused for this many days: no delivery and no visit (the client confirms once a day). null = expired only.
        'prune_after_days' => 90,
        // The server only sends to these push services (no requests to arbitrary hosts). null = any.
        'endpoints' => [
            'fcm.googleapis.com',
            'android.googleapis.com',
            '*.push.services.mozilla.com',
            '*.notify.windows.com',
            '*.push.apple.com',
        ],
        'ttl' => 60 * 60 * 24 * 28,     // seconds the push service keeps an undelivered message
        'urgency' => 'normal',          // very-low, low, normal, high
        'icon' => null,                 // null = /{icons.path}/icon-192.png when it exists
        'badge' => null,                // null = /{icons.path}/monochrome-512.png when it exists
        'handle_in_worker' => true,
        // WebPush::queue(): recipients per queued job, and where the jobs run.
        'queue' => [
            'chunk' => 100,
            'connection' => null,
            'queue' => null,
        ],
        // Requests sent to the push services at once inside one send (minishlink flush).
        'batch_size' => 1000,
        // The worker reports shown, clicked and closed notifications (PushDisplayed, PushClicked,
        // PushDismissed). No user data: only the message id(), tag and action.
        'analytics' => false,
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
