# Changelog

All notable changes to `nyoncode/laravel-pwa` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Inertia offline navigation: the worker handles Inertia visits (GET with `X-Inertia`) like pages. With
  the `pwa.cache` consent it stores the page object per user next to the HTML; offline it answers a visit
  from the stored JSON or from the `data-page` object of the stored HTML of that URL (first load,
  warm-up). Links to stored pages now work offline in React and Vue apps instead of failing with
  `inertia:exception`. Partial reloads are never stored or served from the cache.
- `php artisan pwa:push:prune [--days=] [--pretend]`: deletes push subscriptions past their expiration
  time and those unused (no delivery, no visit) for `push.prune_after_days` days. Schedule it daily.
- `push.prune_after_days` (90 in the package config). The `MassPrunable` subscription model honours it in
  `model:prune` too; a config published before 1.1 has no key and keeps pruning expired subscriptions
  only.

### Development
- The starter kit smoke test checks that an Inertia link to a stored page works offline.

## [1.0.3] - 2026-11-22

### Fixed
- The push toggle did nothing when the browser refused to subscribe although it has the push API (a
  private window in Chrome, a browser without a push service). It now switches to the new `unavailable`
  state: the button is disabled, `<x-pwa::push-toggle/>` shows the `push_unavailable` hint (slot
  `unavailable`), `Pwa.push.state()` returns `unavailable` and `pwa:push` carries `{ state, error }`.

### Documentation
- Troubleshooting: the service worker off in local development, the notification toggle doing nothing,
  notifications that do not arrive, and the 500 on the settings pages of the Livewire starter kit with
  Livewire 4 (a kit issue, with the config fix).

### Development
- `scripts/starter-kit.sh` removes the app after a passing run (`KIT_KEEP=1` keeps it); a failed run
  keeps it for debugging.
- The kill switch browser test no longer fails when the retiring worker's reload lands late (it
  interrupted the next navigation in Firefox and destroyed the page under a check in WebKit).

## [1.0.2] - 2026-11-22

### Fixed
- The keep-alive endpoint (`/pwa/session`) answered 500 in apps whose `config/session.php` reads
  `'lifetime' => env('SESSION_LIFETIME', 120)` without an `(int)` cast (the Livewire starter kit), because
  `config()->integer()` rejects the string from `.env`. Integer and boolean settings, the package's own
  included, now accept env() strings such as `"120"` or `"false"`.
- "Later" on the update toast held only until the next page load, so in a multi-page app the toast came
  back on every page while the same version waited. The choice now holds for the tab (sessionStorage
  `pwa:update-dismissed`); a newer version or a new tab shows the toast again.
- Livewire: an action clicked while offline showed "Changes will be sent once connected", but Livewire
  updates are never queued, so nothing was sent. The banner now says the change was not sent
  (`offline_not_sent`). With `offline.queue` off, the general offline banner no longer promises to send
  changes either (`offline_bar_no_queue`).
- Demo: "Simulate a new version" no longer offers the update a second time after it was applied, and
  the demo pages link a favicon.
- The push toggle ignores clicks while a toggle is in progress, so a double click no longer creates
  two subscriptions.

### Changed
- `pwa:doctor` warns when the user model cannot receive web push (no `HasPushSubscriptions`), explains
  that `pwa.enabled` is off in the `local` environment by default, and points Livewire logins to the
  recipe for `remember`.

## [1.0.1] - 2026-11-21

### Fixed
- Livewire 4: the service worker could queue a Livewire update while offline and replay it later with
  a stale snapshot, because Livewire 4 posts to `/livewire-{hash}/update`, which the default
  `never_cache` (`/livewire/*`) did not cover. Requests with the `X-Livewire` header are now never
  queued, and `never_cache` also lists `/livewire-*`.
- Livewire 4: the adapter uses `Livewire.interceptRequest()` instead of the deprecated
  `Livewire.hook('request')` (Livewire 3 keeps the hook).
- After a 419, the refreshed CSRF token also goes to the `data-csrf` attribute, which Livewire reads
  when the page has no `csrf-token` meta tag.

## [1.0.0] - 2026-11-21

The first stable release. Requires PHP 8.2+ and Laravel 12 or 13.

### Manifest
- Dynamic web app manifest with the display strategies `override`, `adaptive` and `fixed`, built from
  `config/pwa.php` and the fluent `Pwa::manifest()` API (`name()`, `shortName()`, `id()`,
  `startUrl()`, `scope()`, `themeColor()`, `icon()`, `locale()`, `shortcut()`, `shareTarget()`,
  `fileHandler()`, `protocolHandler()`, `launchHandler()`, `windowControlsOverlay()`, `tabbed()`,
  `screenshot()`, `category()`).
- `ManifestValidator`, `pwa:doctor` and `assertValidManifest()` check installability and the OS
  integration members (shortcuts, share target, file and protocol handlers, launch handler), which
  browsers drop silently when they are invalid.
- Per-request manifest resolvers (`Pwa::manifest()->resolveUsing()`, `pwa.manifest.resolvers`,
  `Contracts\ManifestResolver`) with manifest link parameters (`Contracts\ManifestParameters`). They
  run on a copy of the builder, so nothing leaks between requests under Octane.
- Localised manifests: with `pwa.locales` set, `<x-pwa::head/>` links `?lang=` and the manifest is
  built in the page's locale.
- `php artisan pwa:icons`: any, maskable, monochrome and Apple touch icons from one source image (GD
  or Imagick), and iOS startup images for every current iPhone and iPad screen with `--splash`.

### Service worker
- Precache of the offline page, the browser client and the Vite build (`sw.precache_vite`: entries
  with their static imports, CSS and assets by default; `entries`, `all` or `false`), with
  `sw.precache_exclude` patterns and a size warning (`sw.precache_warn_kb`) in `pwa:build` and
  `pwa:doctor`. The worker version follows the configuration, the imported scripts and the build.
- HTML pages are cached only with the server's consent (`pwa.cache` middleware, `X-PWA-Cache: allow`)
  and always per user. A sign-in, a logout or another user's response purges the previous user's data,
  including identity changes from XHR requests of Inertia and Livewire.
- Cache rules (`pwa.cache.rules`, `Pwa::serviceWorker()->cache()`) with the strategies
  `network-first`, `cache-first`, `stale-while-revalidate`, `network-only` and `cache-only`,
  `max_entries`, `max_age`, `destination`, and per-user or `shared` caches.
- Offline queue for mutations, replayed after reconnecting with a fresh CSRF token after 419.
- Offline warm-up (`offline.warm`, `Pwa::warmUsing()`): pages downloaded ahead of time for the
  signed-in user, also through Periodic Background Sync in installed Chromium apps.
- Maintenance mode that tells a 503 from being offline (`pwa:down`), a kill switch
  (`pwa:disable`, `pwa:enable`) and error telemetry.
- `sw.import` (before the runtime, for your own push system) and `sw.extend` (after it, with
  `self.PwaWorker`: `route()`, `strategies`, `cacheName()`, `currentUser()`, `broadcast()`, `warm()`).
- Path tenants on one domain (`pwa.tenancy.resolver`, `Contracts\TenantResolver`): one worker
  registration, manifest and set of caches per tenant scope.

### Session and login
- Persistent login in the installed app (`pwa.remember`), keep-alive through `/pwa/session` with CSRF
  refresh, `X-PWA-Logout` with `Clear-Site-Data: "cache"`.
- 419 retry for `fetch`, `window.axios`, Livewire 3 and Inertia.

### Web push (optional)
- Off by default (`pwa.push.enabled`, needs `minishlink/web-push` ^10 or ^11): `WebPushChannel` for
  Laravel notifications with the fluent `WebPushMessage` (actions, tag, app badge, TTL, urgency,
  topic, Declarative Web Push), the `WebPush` facade, the `HasPushSubscriptions` trait and the
  `PushSubscription` model (publishable migration `pwa::migrations`).
- `php artisan pwa:push:vapid` generates the VAPID keys.
- Endpoints limited to the push services in `pwa.push.endpoints` (checked on subscribe and on every
  send), at most `pwa.push.max_subscriptions` devices per owner, subscriptions removed on logout and
  on 404/410.
- Shared devices: the worker shows a neutral notification when someone else is signed in.
- Worker handlers for `push`, `notificationclick` and `pushsubscriptionchange`
  (`pwa.push.handle_in_worker`).
- `WebPush::queue()` sends to any number of recipients in background jobs (`push.queue.chunk`,
  `chunkById()` for queries, Eloquent recipients serialized as ids); `push.batch_size` for the push
  library. Every send dispatches `PushSent` with the delivery reports.
- Optional statistics (`push.analytics`): the worker reports shown, clicked and closed notifications
  without cookies; `PushDisplayed`, `PushClicked` and `PushDismissed` carry `WebPushMessage::id()`,
  the tag and the action.
- `WebPush::fake()` with `assertSentTo()`, `assertNotSentTo()`, `assertSent()`, `assertSentCount()`,
  `assertNothingSent()`, `assertQueued()` and `assertNothingQueued()`.

### Blade components and browser client
- `<x-pwa::head/>`, `<x-pwa::nav/>` (Back and Reload on desktop), `<x-pwa::install/>` (install button
  and step-by-step "Share → Add to Home Screen" instructions on iPhone and iPad),
  `<x-pwa::update-toast/>` (accept a new version) and `<x-pwa::push-toggle/>` (turn push on and off).
- Optional base styles for the components, themable with `--pwa-*` CSS variables and printed once per
  page with the CSP nonce.
- `window.Pwa`: `install()`, `canInstall()`, `update()`, `updateAvailable()`, `applyUpdate()`,
  `refreshUi()`, `keepAlive()`, `warm()`, `version()`, `badge`, `push` and `on()`, plus `pwa:*` DOM
  events. Vue 3 and React examples in the docs.
- `updates.bar`: the built-in update bar can be turned off for your own UI.

### Artisan, events and testing
- Commands `pwa:install`, `pwa:build`, `pwa:clear`, `pwa:icons`, `pwa:down`, `pwa:disable`,
  `pwa:enable`, `pwa:doctor` and `pwa:push:vapid`; `php artisan about` shows the PWA state.
- Events `ManifestResolving`, `ServiceWorkerBuilt`, `KillSwitchActivated`, `KillSwitchDeactivated`,
  `TelemetryReceived`, `PushSubscribed`, `PushUnsubscribed`, `PushSubscriptionExpired`, `PushFailed`,
  `PushSent`, `PushDisplayed`, `PushClicked`, `PushDismissed`.
- Testing helpers: the `InteractsWithPwa` trait and the assertions `assertPwaCacheable()`,
  `assertNotPwaCacheable()`, `assertPwaLogout()`, `assertPwaUser()` and `assertValidManifest()`.

### Documentation and tooling
- Semantic Versioning with a documented public API ([Versioning](https://nyoncode.github.io/laravel-pwa/versioning.html));
  classes outside it are `@internal`, enforced by architecture tests.
- Documentation in English and Czech at https://nyoncode.github.io/laravel-pwa/, with recipes for the
  Laravel starter kits, Jetstream/Fortify and Filament, and a live demo at
  https://nyoncode.github.io/laravel-pwa/demo/ rendered by the package itself.
- AI support: `ai/AGENTS.md`, a Claude Code skill and a dependency-free MCP server.
- Tested on PHP 8.2–8.5 × Laravel 12/13 (lowest and latest dependencies, 90 % coverage minimum,
  Octane-style state isolation tests, weekly mutation testing); JS unit tests of the worker and the
  client; browser tests in Chromium, Firefox, WebKit and an iPhone project.

[Unreleased]: https://github.com/NyonCode/laravel-pwa/compare/v1.0.3...HEAD
[1.0.3]: https://github.com/NyonCode/laravel-pwa/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/NyonCode/laravel-pwa/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/NyonCode/laravel-pwa/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/NyonCode/laravel-pwa/releases/tag/v1.0.0
