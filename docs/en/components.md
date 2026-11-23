---
title: Blade components
description: "<x-pwa::head/>, <x-pwa::nav/>, <x-pwa::install/>, <x-pwa::update-toast/> and <x-pwa::push-toggle/>: what they render, their attributes, styling, and Vue or React equivalents."
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
<x-pwa::install ios="hint" :dismissible="false"/>
```

| Prop | Default | Description |
| --- | --- | --- |
| slot | translated "Install app" | button text |
| `ios` | `sheet` | iPhone / iPad instructions: `sheet` (two steps with the Share icon), `hint` (one line), `none` |
| `dismissible` | `true` | Close button in the iOS instructions |
| `styles` | `true` | [base styles](#styling) |
| slot `ios-hint` | – | your own content for the iOS instructions |
| attributes | – | passed to the `<button>` (the class is merged with `pwa-install`) |

It renders a hidden `[data-pwa-install]` button and a hidden `[data-pwa-ios-hint]` hint. The client
reveals them when:

- the app isn't installed yet,
- the user has visited it at least `install.min_visits` times,
- the user hasn't dismissed it in the last `install.dismiss_days` days,
- and the browser offers installation (`beforeinstallprompt` or `navigator.install`), or the device
  is an iPhone / iPad (the hint).

To use your own button anywhere, add the `data-pwa-install` attribute to any element; to close the
hint, use `data-pwa-install-dismiss`. `Pwa.canInstall()` and the `pwa:installable` event tell your own
code the same thing.

## `<x-pwa::update-toast/>`

```blade
<x-pwa::update-toast/>
<x-pwa::update-toast class="my-toast">The app was updated.<x-slot:apply>Restart</x-slot:apply></x-pwa::update-toast>
```

| Prop | Default | Description |
| --- | --- | --- |
| slot | translated "A new version is available." | text |
| slot `apply` | translated "Update" | text of the button that activates the new version |
| `dismissible` | `true` | "Later" button; the toast stays hidden in this tab until a newer version installs |
| `styles` | `true` | [base styles](#styling) |
| attributes | – | passed to the toast (`role=status`, class `pwa-toast`) |

Hidden until a new service worker waits. "Update" calls `Pwa.applyUpdate()`: the new worker takes over
and the page reloads. With the toast on the page, the built-in [update bar](client.md#service-worker-updates)
is not shown. Your own markup works the same way: `[data-pwa-update]` (shown while an update waits),
`[data-pwa-update-apply]` and `[data-pwa-update-dismiss]`.

## `<x-pwa::push-toggle/>`

```blade
<x-pwa::push-toggle class="btn"/>
<x-pwa::push-toggle>
    <x-slot:enable>Notify me about my orders</x-slot:enable>
</x-pwa::push-toggle>
```

| Prop / slot | Default | Description |
| --- | --- | --- |
| slot `enable` | translated "Turn on notifications" | button text when not subscribed |
| slot `disable` | translated "Turn off notifications" | button text when subscribed |
| slot `denied` | translated hint | shown when the user blocked notifications |
| slot `install-required` | translated hint | iPhone / iPad in Safari: push works only in the Home Screen app |
| slot `unavailable` | translated hint | the browser refused to subscribe (a private window, no push service) |
| `styles` | `true` | [base styles](#styling) |
| attributes | – | passed to the `<button>` (class `pwa-push__toggle`) |

Needs the [web push module](push.md). A click subscribes or unsubscribes this device
(`Pwa.push.toggle()`); the permission prompt appears only then. The wrapper gets
`data-pwa-push-state` (`subscribed`, `unsubscribed`, `denied`, `install-required`, `unavailable`), the button
`aria-pressed`, and the matching `[data-pwa-push-label]` is shown. Without push on the server, or in a
browser without push, the toggle stays hidden.

## `<x-pwa::outbox/>`

```blade
<x-pwa::outbox class="card"/>
<x-pwa::outbox>
    <x-slot:title>Unsaved orders</x-slot:title>
    These orders were not saved. Check them and try again.
</x-pwa::outbox>
```

| Prop / slot | Default | Description |
| --- | --- | --- |
| slot `title` | translated "Not saved" | heading |
| default slot | translated explanation | text under the heading |
| `styles` | `true` | [base styles](#styling) |
| attributes | – | passed to the wrapper (class `pwa-ui pwa-outbox`) |

Changes made offline that the server refused when they were sent ([outbox](offline.md#outbox)): each
row shows the method and path, the server's message or a translated reason, the validation errors, and
**Try again** / **Discard**. Hidden while the outbox is empty, it is not a floating toast: place it where
the user looks, for example above the content. The rows come from
`<template data-pwa-outbox-item>` inside the component, with `[data-pwa-outbox-field]` (`method`,
`path`, `status`, `reason`), `[data-pwa-outbox-errors]`, `[data-pwa-outbox-retry]` and
`[data-pwa-outbox-discard]`; publish the views to change it. With the component on the page, the
built-in "could not be saved" bar is not shown.

## Styling

`install`, `update-toast` and `push-toggle` print a small stylesheet once per page (with the CSP nonce).
Theme it with CSS variables, or pass `:styles="false"` and style the `pwa-*` classes yourself.
The rules use `:where()`, so any class of yours wins.

```css
.pwa-ui {
  --pwa-surface: #0f172a;   /* background of the toast and the iOS sheet */
  --pwa-on-surface: #fff;   /* text */
  --pwa-muted: #cbd5e1;     /* secondary text */
  --pwa-action: #2563eb;    /* buttons */
  --pwa-radius: 16px;
  --pwa-shadow: 0 8px 24px rgb(0 0 0 / .2);
  --pwa-font: 500 15px/1.4 Inter, sans-serif;
}
```

All logic lives in the client (`pwa.js`), so the components need no inline script.
Elements added later (Livewire `wire:navigate`, Inertia, a Vue or React render) are picked up on
`livewire:navigated`, `inertia:navigate`, or when you call `Pwa.refreshUi()`.

## Vue and React

The Blade components only render markup with `data-pwa-*` attributes. In Vue or React, render the same
attributes or use the client API directly.

```js
// resources/js/composables/usePwa.js (Vue 3)
import { onMounted, onUnmounted, ref } from 'vue'

export function usePwa() {
  const canInstall = ref(window.Pwa?.canInstall() ?? false)
  const updateAvailable = ref(window.Pwa?.updateAvailable() ?? false)
  const push = ref('unsupported')
  const offs = []

  onMounted(async () => {
    offs.push(Pwa.on('installable', () => (canInstall.value = Pwa.canInstall())))
    offs.push(Pwa.on('installed', () => (canInstall.value = false)))
    offs.push(Pwa.on('update-available', () => (updateAvailable.value = true)))
    offs.push(Pwa.on('push', ({ state }) => (push.value = state)))
    push.value = await Pwa.push.state()
  })
  onUnmounted(() => offs.forEach((off) => off()))

  return { canInstall, updateAvailable, push, install: () => Pwa.install(), update: () => Pwa.applyUpdate(), togglePush: () => Pwa.push.toggle() }
}
```

```jsx
// resources/js/hooks/usePwa.js (React)
import { useEffect, useState } from 'react'

export function usePwa() {
  const [canInstall, setCanInstall] = useState(() => window.Pwa?.canInstall() ?? false)
  const [updateAvailable, setUpdateAvailable] = useState(() => window.Pwa?.updateAvailable() ?? false)
  const [push, setPush] = useState('unsupported')

  useEffect(() => {
    const offs = [
      Pwa.on('installable', () => setCanInstall(Pwa.canInstall())),
      Pwa.on('installed', () => setCanInstall(false)),
      Pwa.on('update-available', () => setUpdateAvailable(true)),
      Pwa.on('push', ({ state }) => setPush(state)),
    ]
    Pwa.push.state().then(setPush)
    return () => offs.forEach((off) => off())
  }, [])

  return { canInstall, updateAvailable, push, install: () => Pwa.install(), update: () => Pwa.applyUpdate(), togglePush: () => Pwa.push.toggle() }
}
```

`window.Pwa` exists once the client module has run; for server-side rendering, call the hook only in
the browser. Call `Pwa.push.toggle()` and `Pwa.install()` from a click handler.

## Offline and maintenance

The `pwa::offline` and `pwa::maintenance` screens aren't components but full pages. After publishing
the views, edit them in `resources/views/vendor/pwa/`. They must not use external resources (inline
your CSS, fonts and images): nothing is reachable offline, and during maintenance mode not even your assets are.
