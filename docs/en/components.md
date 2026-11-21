---
title: Blade components
description: "<x-pwa::head/>, <x-pwa::nav/> and <x-pwa::install/>: what they render, their attributes, and how to customize them."
---

# Blade components

All components are anonymous Blade components in the `pwa` namespace. To customize them, run
`php artisan vendor:publish --tag="pwa::views"` and edit the files in `resources/views/vendor/pwa/components/`.

## `<x-pwa::head/>`

Goes in `<head>`. It renders:

```html
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0d6efd" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b1220" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="My App">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="/pwa/icons/apple-touch-icon.png">
<link rel="apple-touch-startup-image" media="screen and (device-width: 402px) and (device-height: 874px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/pwa/icons/splash/apple-splash-1206x2622.png">
<script type="application/json" id="pwa-config">{…}</script>
<script type="module" src="/pwa/client.js?v=…" nonce="…"></script>
```

- `apple-touch-icon` is rendered only if the file exists; `apple-touch-startup-image` once per splash
  image from `pwa:icons --splash`, with a media query for its screen.
- `#pwa-config` carries the client configuration (`Pwa::clientConfig()`); CSP doesn't block the `application/json` type.
- The client script gets a `nonce` from `Vite::useCspNonce()` when your app sets one.
- `?v=` is the file hash, so `Cache-Control: immutable` is safe.

The viewport (`viewport-fit=cover`) and `csrf-token` belong in your app layout, not in the component.

## `<x-pwa::nav/>`

Back and Reload buttons for desktop, where the browser doesn't draw them. See
[Display mode](display-modes.md#back-and-reload-buttons).

| Prop | Default | Description |
| --- | --- | --- |
| `start-url` | `config('pwa.start_url')` | Back is disabled on this page. |

It shows when `(pointer: fine)` matches, the width is ≥ `desktop_nav.min_width`, and the display mode is
`standalone` or `window-controls-overlay`. In WCO it sits in the title bar (`position: fixed` based on
`env(titlebar-area-*)`); otherwise it stays in the page flow.

Styling: the `.pwa-nav` and `.pwa-nav__btn` classes, with color from `currentColor`. It responds to
`popstate`, `livewire:navigated` and `inertia:navigate`.

## `<x-pwa::install/>`

```blade
<x-pwa::install class="btn btn-primary">Install app</x-pwa::install>
<x-pwa::install :dismissible="false"/>
```

| Prop | Default | Description |
| --- | --- | --- |
| slot | translated "Install app" | button text |
| `dismissible` | `true` | Close button in the iOS hint |
| attributes | – | passed to the `<button>` (the class is merged with `pwa-install`) |

It renders a hidden `[data-pwa-install]` button and a hidden `[data-pwa-ios-hint]` hint. The client
reveals them when:

- the app isn't installed yet,
- the user has visited it at least `install.min_visits` times,
- the user hasn't dismissed it in the last `install.dismiss_days` days,
- and the browser offers installation (`beforeinstallprompt` or `navigator.install`), or the device
  is an iPhone / iPad (the hint).

To use your own button anywhere, add the `data-pwa-install` attribute to any element; to close the
hint, use `data-pwa-install-dismiss`.

## Offline and maintenance

The `pwa::offline` and `pwa::maintenance` screens aren't components but full pages. After publishing
the views, edit them in `resources/views/vendor/pwa/`. They must not use external resources (inline
your CSS, fonts and images): nothing is reachable offline, and during maintenance mode not even your assets are.
