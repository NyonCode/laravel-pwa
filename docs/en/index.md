---
title: Overview
description: What the nyoncode/laravel-pwa package does, what it is made of, and where to start.
---

# Laravel PWA

`nyoncode/laravel-pwa` turns your Laravel application into an installable Progressive Web App: one
app at one address. On desktop it runs in its own window with Back and Reload buttons, on phones and
tablets it runs full screen. It works without a connection, survives maintenance mode, and the
installed app stays signed in.

Try it in the [live demo](https://nyoncode.github.io/laravel-pwa/demo/): install it, go offline and accept
an update.

It supports Laravel 12 and 13 and PHP 8.2+. It is built on
[`nyoncode/laravel-package-toolkit`](https://github.com/NyonCode/laravel-package-toolkit).

## What it is made of

| Part | What it does | Page |
| --- | --- | --- |
| Manifest | `/manifest.webmanifest` from config and a fluent API, display mode, icons, OS integration | [Manifest](manifest.md), [Display modes](display-modes.md) |
| Components | `<x-pwa::head/>`, `<x-pwa::nav/>`, `<x-pwa::install/>` | [Components](components.md) |
| Service worker | `/sw.js` built from the Vite build, cache strategies, updates | [Service worker](service-worker.md) |
| Offline | offline screen, pages available offline, offline queue | [Offline](offline.md) |
| Session | remember-me in the installed app, keep-alive, CSRF refresh | [Persistent login](session.md) |
| Maintenance | maintenance screen, `pwa:down`, telling a 503 apart from a network failure | [Maintenance mode](maintenance.md) |
| Kill switch | `pwa:disable` removes the service worker from every device | [Kill switch](kill-switch.md) |
| Client | `window.Pwa`, `pwa:*` events | [JavaScript client](client.md) |
| Push | optional: notification channel, `Pwa.push`, per-device subscriptions; or a slot for your own push system | [Push notifications](push.md) |
| AI | MCP server, skill and reference for coding agents | [AI agents](ai.md) |

## What it does not do

- **It does not push on its own.** The push module stays off until you enable it, and the browser
  asks for permission only when your code calls `Pwa.push.subscribe()`. See
  [Push notifications](push.md).
- **It does not build your frontend.** It takes assets from your app's Vite build
  (`public/build/manifest.json`).
- **It does not decide what is available offline.** No page is cached until the server allows it
  with the `pwa.cache` middleware.

## Quick start

```bash
composer require nyoncode/laravel-pwa
php artisan pwa:install
php artisan pwa:icons resources/pwa/icon.png
```

```blade
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <x-pwa::head/>
</head>
```

Continue with [Installation](installation.md).
