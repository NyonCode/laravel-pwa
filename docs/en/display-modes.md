---
title: Display mode
description: minimal-ui on desktop, standalone on phones and tablets; the override, adaptive and fixed strategies and the Back and Reload buttons.
---

# Display mode

One app at one address, but a different window per device: `minimal-ui` with Back and Reload
buttons on desktop, full-screen `standalone` on phones and tablets.

## Modes

| Mode | What it looks like |
| --- | --- |
| `fullscreen` | the whole screen with no system bars (games, kiosks) |
| `standalone` | its own window like a native app, with no address bar |
| `minimal-ui` | its own window with minimal controls: Back, Reload, URL (Edge and Chrome on desktop) |
| `browser` | a regular browser tab |
| `window-controls-overlay` | the app also draws the title bar (`display_override` only, Chromium desktop) |
| `tabbed` | tabs inside the app (`display_override` only, Chromium desktop) |

## The `override` strategy (default)

```json
{ "display": "standalone", "display_override": ["minimal-ui"] }
```

| Device | Result | Why |
| --- | --- | --- |
| Windows, macOS, Linux, ChromeOS – Edge, Chrome | `minimal-ui` | Chromium on desktop reads `display_override` |
| Android phone and tablet – Chrome, Samsung Internet | `standalone` | Chrome on Android does not use `minimal-ui` from the override (tested since Chrome 91) |
| iPhone, iPad | `standalone` | Safari ignores `display_override` |
| Mac – Safari in the Dock | `standalone` | same as above |
| Firefox on Windows | its own window without bars | Firefox does not read the manifest for the mode |

The upside: the manifest is the same for everyone, it is cacheable (`Cache-Control: public`) and it
needs no device detection. Verify the Android behavior on a real device; if it differs, switch to
`adaptive`.

## The `adaptive` strategy

```php
'display' => ['strategy' => 'adaptive', 'value' => 'standalone', 'desktop' => 'minimal-ui'],
```

The server decides on every manifest request:

1. The `Sec-CH-UA-Platform` header (Client Hints in Chromium): Windows, macOS, Linux, Chrome OS = desktop.
2. Without it, the User-Agent: `Windows NT`, `X11; Linux`, `CrOS` = desktop.
3. Everything else = `value`.

Safari always reports itself as a Mac (even on iPad), so without Client Hints macOS is not treated
as desktop and Safari gets `standalone`. Android tablets often send a desktop User-Agent, but
Client Hints say `Android`, so they get `standalone`.

The manifest is then sent with `Vary: Sec-CH-UA-Platform, User-Agent`, `Accept-CH` and
`Cache-Control: private`. Watch out for CDNs that do not respect `Vary`.

## The `fixed` strategy

```php
'display' => ['strategy' => 'fixed', 'value' => 'standalone'],
```

Only `display`, without `display_override`.

## More desktop modes

```php
Pwa::manifest()->windowControlsOverlay(); // display_override: [window-controls-overlay, minimal-ui]
Pwa::manifest()->tabbed(['new_tab_button' => ['url' => '/orders/create']]);
```

Both are added before `minimal-ui`, so a browser that does not support them falls back to
`minimal-ui`. In WCO the app draws the title bar itself: use the CSS variables
`env(titlebar-area-x)`, `env(titlebar-area-width)`, `env(titlebar-area-height)` and
`app-region: drag`.

## Back and Reload buttons

In `minimal-ui` the browser draws them. Where they are missing,
[`<x-pwa::nav/>`](components.md#x-pwanav) renders them:

| Situation | Buttons |
| --- | --- |
| Edge / Chrome in `minimal-ui` | native, component hidden |
| Edge / Chrome with WCO | component in the title bar |
| Safari in the Dock, Firefox, desktop in `standalone` | component in the app header |
| phone, tablet | hidden (system back gesture, pull-to-refresh) |

## Detecting the mode in your app

CSS:

```css
@media (display-mode: standalone) { .only-in-browser { display: none; } }
@media (display-mode: minimal-ui) { /* desktop window */ }
```

JavaScript: `window.Pwa.displayMode()` returns `window-controls-overlay`, `tabbed`, `fullscreen`,
`standalone`, `minimal-ui` or `browser`.

PHP: `Pwa::isStandaloneRequest($request)` (the `X-PWA-Display` header, the `pwa_display` cookie,
`?source=pwa`, or an earlier session mark).

## Changing the mode after install

Browsers check the manifest of an installed app periodically (Chrome roughly once a day at
launch). A mode change therefore shows up with a delay. Never change `id`.
