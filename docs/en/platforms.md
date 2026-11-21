---
title: Platform support
description: What works on iOS, Android, desktop Chromium, Safari and Firefox, and how the package handles each.
---

# Platform support

Current as of November 2026. The package always detects a capability in the browser, never the
browser brand, and silently skips whatever a platform lacks.

| Capability | iOS / iPadOS | Android | Desktop Chromium | Safari macOS | Firefox |
| --- | --- | --- | --- | --- | --- |
| Installation | manual (Share → Add to Home Screen), "Open as Web App" by default since iOS 26 | prompt | prompt, `navigator.install()` | Add to Dock | Windows: taskbar (143+) |
| Display mode from the manifest | `standalone` | `standalone` | `minimal-ui` | `standalone` | own window |
| `display_override` | no | yes (without minimal-ui) | yes | no | no |
| Service worker, offline | yes | yes | yes | yes | yes |
| Navigation Preload | yes | yes | yes | yes | yes |
| Background Sync | no → the queue is sent when the user returns | yes | yes | no | no |
| Badge | 16.4+ | yes | yes | yes | no |
| Web Push | installed app only (16.4+) | yes | yes | yes | yes |
| shortcuts, share_target | no | yes | yes | no | no |
| file/protocol handlers, WCO, tabbed, launch_handler | no | no | yes | no | no |
| Persistent storage | the app has its own day counter | yes | yes | 7-day ITP outside the Dock | yes |

## iPhone and iPad

- Installation is manual only; `<x-pwa::install/>` shows a hint.
- iOS ignores `theme_color` from the manifest; the package sends `apple-mobile-web-app-*` meta tags.
- Notch and Dynamic Island: `viewport-fit=cover` + `env(safe-area-inset-*)`.
- The Home Screen app can have different cookies than Safari.
- The iOS Simulator doesn't support push; test on a device.

## Android

- Tablets and foldables run the same Chrome; drive your layout with media/container queries.
- Samsung Internet has its own install UI; test it separately.
- A maskable icon is required; otherwise the system shrinks your icon into a white circle.

## Desktop

- Edge and Chrome: `minimal-ui` with Back and Reload buttons, optionally WCO and tabs.
- Safari in the Dock: `standalone`; `<x-pwa::nav/>` provides the buttons.
- Firefox (Windows): web apps without manifest integrations; push and offline work.

## Pre-release test matrix

| Device | Browsers |
| --- | --- |
| iPhone (iOS 26+) | Safari, Home Screen app |
| iPad | Safari, Home Screen app, Stage Manager |
| Android phone | Chrome, Samsung Internet |
| Android tablet | Chrome |
| Windows | Edge, Chrome, Firefox |
| macOS | Chrome, Safari in the Dock |
| ChromeOS | Chrome |

For each one, test: installation, window mode, the offline page, a cached page, the queue, maintenance
mode, service worker updates, and login after several days.
