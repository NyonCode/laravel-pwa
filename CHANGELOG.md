# Changelog

All notable changes to `nyoncode/laravel-pwa` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-11-21

Upgrading from 0.3? See [UPGRADE.md](https://github.com/NyonCode/laravel-pwa/blob/main/UPGRADE.md).

### Added
- Optional web push module (`pwa.push.enabled`, off by default; needs `minishlink/web-push` ^10 or
  ^11): `Push\WebPushChannel` for Laravel notifications (also `'webpush'`) with the fluent
  `Push\WebPushMessage` (actions, tag, app badge, TTL, urgency, topic, Declarative Web Push), the
  `WebPush` facade for direct sends, the `Push\HasPushSubscriptions` trait and the
  `Push\PushSubscription` model (publishable migration `pwa::migrations`, `model:prune` for expired
  rows).
- `php artisan pwa:push:vapid` generates the VAPID key pair with OpenSSL and writes it to `.env`.
- Subscription routes `POST|DELETE /pwa/push/subscriptions` (web, `auth`, throttled). Endpoints must
  be https URLs of the push services in `pwa.push.endpoints`, checked again before every send, so
  users cannot make the server call other hosts. At most `pwa.push.max_subscriptions` devices per
  owner.
- Shared devices: logout removes the subscriptions of that session, a device moves to the user who
  subscribes on it, and the worker shows a neutral notification instead of the content when another
  user (or nobody) is signed in.
- Worker: `push`, `notificationclick` (same-origin URLs only, focuses or reuses an app window) and
  `pushsubscriptionchange` (renewal accepted only in place of the user's own endpoint);
  `pwa.push.handle_in_worker = false` leaves them to your `sw.import` code.
- Client: `Pwa.push.supported()`, `permission()`, `state()`, `subscribe()`, `unsubscribe()`,
  `subscription()`; daily confirmation with the server, renewal after a key change, events
  `pwa:push`, `pwa:push-received`, `pwa:push-renewed` and `pwa:notification-click`.
- Events `PushSubscribed`, `PushUnsubscribed`, `PushSubscriptionExpired` (404/410, row deleted) and
  `PushFailed`; `Contracts\PushTransport` to replace the delivery library.
- `WebPush::fake()` with `assertSentTo()`, `assertNotSentTo()`, `assertSent()`, `assertSentCount()`,
  `assertNothingSent()`.
- `pwa:doctor` checks the push library, the VAPID keys and subject, the subscriptions table and the
  endpoint allowlist when push is enabled; `php artisan about` shows the push state.
- `Pwa::identityHash()` returns the user hash for any user id.

## [0.3.0] - 2026-11-21

Upgrading from 0.2? See [UPGRADE.md](https://github.com/NyonCode/laravel-pwa/blob/main/UPGRADE.md).

### Added
- iOS startup images: `php artisan pwa:icons --splash` renders `apple-touch-startup-image` files for
  every current iPhone and iPad screen (portrait and landscape, optional dark variants) and
  `<x-pwa::head/>` links the ones that exist. Configured under `pwa.icons.splash`.
- `monochrome-512.png` (`purpose: monochrome`) generated from the alpha channel of the source and
  listed in the manifest.
- Offline warm-up: `offline.warm.urls` and `Pwa::warmUsing()` list pages the worker downloads ahead
  of time, so they open offline without a prior visit. The list travels in `/pwa/session`
  (`no-store`); only responses with `X-PWA-Cache: allow` for the same user are stored. It runs after
  a sign-in, at most once per `offline.warm.interval` on keep-alive, on Periodic Background Sync in
  installed Chromium apps and on `Pwa.warm()`. New client events `pwa:identity` and `pwa:warmed`,
  `self.PwaWorker.warm()` for `sw.extend` files.
- Path tenants on one domain: `pwa.tenancy.resolver` / `Pwa::resolveTenantsUsing()` with
  `Contracts\TenantResolver` and `Tenancy\Tenant`. Each tenant (`/t/acme/`) gets its own worker
  registration for its scope, its own manifest `id`, `scope`, `start_url` and name (through
  `?tenant=` on the manifest link) and namespaced caches (`pwa-@t-acme.{hash}-…`). The signed-in
  user stays shared: a sign-in or logout under any tenant purges the user data of all of them. Recipes for stancl/tenancy and spatie/laravel-multitenancy in the docs.
- `pwa:doctor` checks screenshots (a `wide` and a `narrow` one, file exists, `sizes` and `type`
  match the file) and reports iOS splash screens.

### Fixed
- Client status bar texts that were still in Czech ("Jste offline.") are now English.
- Docs named the 0.1 cache `pwa-api-{user}`; rule caches are `pwa-rule-{name}-{user}`.
- The offline screen lists only pages of the worker that controls it.
- Comments left in Czech in the views are now English.

### Changed
- Icon rendering moved from `pwa:icons` into `Icons\IconGenerator` with GD and Imagick drivers
  behind `Icons\ImageDriver`. Invalid colour messages now read "Invalid colour […]".

## [0.2.0] - 2026-11-21

Upgrading from 0.1? See [UPGRADE.md](https://github.com/NyonCode/laravel-pwa/blob/main/UPGRADE.md).

### Added
- Cache rules: `pwa.cache.rules` and the fluent `Pwa::serviceWorker()->cache()` with the strategies
  `network-first` (optional timeout), `cache-first`, `stale-while-revalidate`, `network-only` and
  `cache-only`, plus `max_entries`, `max_age`, `destination` and per-user or `shared` caches.
- `pwa.sw.extend`: scripts loaded after the runtime with `self.PwaWorker` (`route()`, `strategies`,
  `cacheName()`, `currentUser()`, `broadcast()`) for custom routes with the same user separation.
- `pwa.cache.images.enabled` to turn off the default image and font rule.
- Per-request manifest resolvers: `Pwa::manifest()->resolveUsing()` and `pwa.manifest.resolvers`
  (`Contracts\ManifestResolver`), with manifest link parameters (`Contracts\ManifestParameters`)
  because the manifest route has no session. Resolvers run on a copy of the builder (Octane-safe).
- Typed manifest members `name()`, `shortName()`, `description()`, `id()`, `startUrl()`, `scope()`,
  `themeColor()`, `backgroundColor()`, `icon()`, `locale()`, plus `private()` and `vary()` for the
  manifest's cache headers, and `forRequest()`, `parameters()`, `url()`.
- `<x-pwa::head/>` takes `theme-color` and the iOS title from the resolved manifest.
- Events `ManifestResolving`, `ServiceWorkerBuilt`, `KillSwitchActivated`, `KillSwitchDeactivated`
  and `TelemetryReceived`; telemetry logging moved to the `LogTelemetry` listener
  (`pwa.telemetry.log` turns it off).
- Testing helpers for applications: the `InteractsWithPwa` trait (`asInstalledApp()`,
  `asBrowserTab()`) and the TestResponse assertions `assertPwaCacheable()`, `assertNotPwaCacheable()`,
  `assertPwaLogout()`, `assertPwaUser()` and `assertValidManifest()`, backed by `ManifestValidator`.
- `pwa:doctor` validates cache rules and warns about shared rules over never-cached paths.
- Unit tests for the service worker (`tests/js/sw`) and the browser client (`tests/js/client`),
  run with `node:test` against the real source files.
- Playwright browser tests (`tests/e2e`) in Chromium and Firefox, replacing the Python script.
- GitHub Actions for Pest (PHP 8.2–8.5 × Laravel 12/13 × lowest/stable), coverage (minimum 90 %),
  PHPStan, Pint, Rector, `composer audit`, ESLint, JS unit tests, e2e tests and releases from this file.
- `composer check`, `composer test:coverage`, `composer rector`; contributor guide and issue templates.
- English documentation in `docs/en` (now the primary language) with the full Czech translation in
  `docs/cs`, an English README (`README.cs.md` in Czech) and `npm run docs:check`, which CI runs to
  keep both languages in step (pages, heading outline, code blocks, links and anchors).

### Fixed
- The manifest is built in the page's locale: with `pwa.locales` set, `<x-pwa::head/>` links
  `?lang=` and the manifest route uses it (it has no session, so every user got the default locale).
- The `CLEAR_USER` message now also resets the worker's current user, so data fetched right after
  it is no longer stored under the previous user.
- `cache.images.max_age` is now enforced: older images are refetched (and still served while offline).
- Localised manifest members (`name_localized` …) no longer label the fallback locale's text as
  another language when a translation is missing.
- `pwa:icons` rejects an invalid `--background` colour instead of silently using white, and accepts
  short `#rgb` colours.

### Security
- Includes the identity fix from 0.1.1
  ([GHSA-mjp7-hj6c-2jr9](https://github.com/NyonCode/laravel-pwa/security/advisories/GHSA-mjp7-hj6c-2jr9)),
  ported to the cache rules engine, with its regression tests in `tests/js/sw/identity.test.mjs`.

### Changed
- API and image caches are rule caches now (`pwa-rule-api-*`, `pwa-rule-images-*`); the worker
  removes the 0.1 caches on activation. See UPGRADE.md.
- Invalid cache configuration fails `pwa:build` with a message naming the entry.
- PHPStan (Larastan) level raised from 6 to max; stricter Pint rules (imports, strict comparison,
  void returns).
- Config values are read with typed accessors: a wrongly typed value fails with a message naming the
  key instead of being cast silently. `pwa.enabled` still accepts env strings such as `"1"`. See UPGRADE.md.
- Documentation moved from `docs/` to `docs/en` and `docs/cs`; the MCP server serves the English pages.
- Artisan output, command descriptions, exception messages and code comments are in English.
- English `composer.json` description and keywords, plus `support` links.

## [0.1.1] - 2026-11-21

### Security
- The service worker reads the user identity (`X-PWA-User`, `X-PWA-Logout`) from every same-origin
  response, not only from navigations. Before, after an XHR logout and a login of another user
  (Inertia, Livewire) on the same device, the previous user's `cache.api` data stayed readable.
  fetch/XHR requests to `cache.never_cache` paths pass through the worker only to read the identity
  (never stored or queued), mutations are read also with `offline.queue = false`, per-user caches
  only accept their owner's responses, identity headers from other origins (also after a redirect)
  are ignored, and the client forwards the identity from each keep-alive (`IDENTITY` message).
  [GHSA-mjp7-hj6c-2jr9](https://github.com/NyonCode/laravel-pwa/security/advisories/GHSA-mjp7-hj6c-2jr9)

### Fixed
- The `CLEAR_USER` message also resets the worker's current user, so data fetched right after it is
  no longer stored under the previous user.

## [0.1.0] - 2026-11-21

### Added
- Dynamic web app manifest with display strategies `override`, `adaptive` and `fixed`, and a fluent API.
- Service worker: precache from the Vite build, page caching only with server consent, per-user cache
  separation, offline queue for mutations, maintenance (503) detection, error telemetry, `sw.import`.
- Offline and maintenance screens (`pwa:down`) with CSP nonce and hash support.
- Persistent login: `pwa.remember`, keep-alive `/pwa/session`, CSRF refresh, `X-PWA-Logout`.
- Components `<x-pwa::head/>`, `<x-pwa::nav/>`, `<x-pwa::install/>`.
- Commands `pwa:install`, `pwa:build`, `pwa:clear`, `pwa:icons`, `pwa:down`, `pwa:disable`,
  `pwa:enable`, `pwa:doctor`.
- Livewire 3 and Inertia adapters (including the 419 retry through `window.axios`).
- AI support: `ai/AGENTS.md`, the `laravel-pwa` Claude Code skill, a dependency-free MCP server
  (`search_docs`, `get_doc`, `describe_api`, `describe_config`, `list_commands`, `describe_client` …)
  and the `vendor/bin/pwa-ai` installer.
- Documentation in `docs/` (21 pages, ordered by `docs/nav.json`).

[Unreleased]: https://github.com/NyonCode/laravel-pwa/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/NyonCode/laravel-pwa/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/NyonCode/laravel-pwa/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/NyonCode/laravel-pwa/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/NyonCode/laravel-pwa/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/NyonCode/laravel-pwa/releases/tag/v0.1.0
