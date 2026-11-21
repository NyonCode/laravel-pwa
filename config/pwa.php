<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zapnutí
    |--------------------------------------------------------------------------
    |
    | Lokálně je service worker ve výchozím stavu vypnutý, aby se nepletl
    | s Vite HMR. Když je vypnutý, klient případný starý SW odregistruje.
    | Manifest a komponenty fungují i tak.
    |
    */

    'enabled' => env('PWA_ENABLED', env('APP_ENV') !== 'local'),

    /*
    |--------------------------------------------------------------------------
    | Identita aplikace
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
    | Režim zobrazení
    |--------------------------------------------------------------------------
    |
    | override: display = value, display_override = override (bez detekce
    |           zařízení: Edge/Chrome na desktopu dostanou minimal-ui,
    |           telefony, tablety a Safari standalone)
    | adaptive: server rozhodne podle Sec-CH-UA-Platform / User-Agent
    | fixed:    jen display = value
    |
    */

    'display' => [
        'strategy' => env('PWA_DISPLAY_STRATEGY', 'override'),
        'value' => 'standalone',
        'override' => ['minimal-ui'],
        'desktop' => 'minimal-ui',   // jen pro strategii adaptive
    ],

    'orientation' => null,

    /*
    |--------------------------------------------------------------------------
    | Tlačítka Zpět / Obnovit na desktopu (<x-pwa::nav/>)
    |--------------------------------------------------------------------------
    */

    'desktop_nav' => [
        'enabled' => true,
        'min_width' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Barvy a ikony
    |--------------------------------------------------------------------------
    */

    'theme' => [
        'light' => '#0d6efd',
        'dark' => '#0b1220',
        'accent_dark' => '#5b9dff',
        'background' => '#ffffff',
    ],

    'icons' => [
        'source' => null,               // cesta k SVG/PNG pro pwa:icons, např. resource_path('pwa/icon.png')
        'path' => 'pwa/icons',          // složka v public/
        'maskable_padding' => 0.1,
        'sizes' => [192, 512],
    ],

    'screenshots' => [],

    /*
    |--------------------------------------------------------------------------
    | Integrace do systému
    |--------------------------------------------------------------------------
    */

    'categories' => [],

    'shortcuts' => [
        // ['name' => 'Nová objednávka', 'url' => '/orders/create', 'icon' => '/pwa/icons/shortcut-new.png'],
    ],

    'share_target' => null,

    'file_handlers' => [],

    'protocol_handlers' => [],

    'launch_handler' => ['client_mode' => ['navigate-existing', 'auto']],

    'scope_extensions' => [],

    'locales' => [],                    // další jazyky pro lokalizovaný manifest, např. ['en', 'de']

    /*
    |--------------------------------------------------------------------------
    | Service worker
    |--------------------------------------------------------------------------
    */

    'sw' => [
        'url' => '/sw.js',
        // Soubory, které SW načte přes importScripts (např. push systém).
        'import' => [],
        // Kde je Vite manifest pro precache.
        'vite_manifest' => 'build/manifest.json',
        'build_directory' => 'build',
        // Kam pwa:build uloží sestavený SW.
        'compiled_path' => 'bootstrap/cache/pwa',
        // Úložiště cache pro příznak nouzového vypnutí (null = výchozí).
        'kill_switch_store' => env('PWA_KILL_SWITCH_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategie cache
    |--------------------------------------------------------------------------
    |
    | HTML se do cache ukládá jen s hlavičkou X-PWA-Cache: allow (middleware
    | pwa.cache). Cesty v never_cache jdou vždy na síť.
    |
    */

    'cache' => [
        'navigation_timeout' => 3,
        'api' => [],                    // GET cesty s network-first a záložní cache, např. ['/api/catalog*']
        'images' => ['max_entries' => 120, 'max_age' => 60 * 60 * 24 * 30],
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
        'queue' => true,                // fronta POST/PUT/PATCH/DELETE při výpadku sítě
    ],

    /*
    |--------------------------------------------------------------------------
    | Session (trvalé přihlášení v nainstalované aplikaci)
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
    | Režim údržby (pwa:down)
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
    | Instalace
    |--------------------------------------------------------------------------
    */

    'install' => [
        'min_visits' => 2,
        'dismiss_days' => 30,
        'ios_hint' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetrie (chyby ze service workeru)
    |--------------------------------------------------------------------------
    */

    'telemetry' => [
        'enabled' => true,
        'channel' => env('PWA_LOG_CHANNEL'),
        'max_events' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Livewire a Inertia
    |--------------------------------------------------------------------------
    */

    'adapters' => [
        'livewire' => true,
        'inertia' => true,
        'reload_on_resume' => false,
    ],

    'route_prefix' => 'pwa',
];
