# Changelog

All notable changes to `nyoncode/laravel-pwa` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- `pwa:doctor` validates cache rules and warns about shared rules over never-cached paths.
- Unit tests for the service worker (`tests/js/sw`) and the browser client (`tests/js/client`),
  run with `node:test` against the real source files.
- Playwright browser tests (`tests/e2e`) in Chromium and Firefox, replacing the Python script.
- GitHub Actions for Pest (PHP 8.2–8.5 × Laravel 12/13 × lowest/stable), coverage (minimum 90 %),
  PHPStan, Pint, Rector, `composer audit`, ESLint, JS unit tests, e2e tests and releases from this file.
- `composer check`, `composer test:coverage`, `composer rector`; contributor guide and issue templates.

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
- The worker now reads the user identity (`X-PWA-User`, `X-PWA-Logout`) from every same-origin
  response, not only from navigations. Before, an XHR logout and login of another user (Inertia,
  Livewire) on the same device left the previous user's API data readable through `pwa.cache.api`.
  `never_cache` fetch/XHR requests and mutations (also with `offline.queue = false`) now pass
  through the worker for this (never_cache still never stored or queued), per-user caches only store
  responses of their owner, identity headers of other origins (also after a redirect) are ignored,
  and the client forwards the identity from each keep-alive (`IDENTITY` message).

### Changed
- API and image caches are rule caches now (`pwa-rule-api-*`, `pwa-rule-images-*`); the worker
  removes the 0.1 caches on activation. See UPGRADE.md.
- Invalid cache configuration fails `pwa:build` with a message naming the entry.
- PHPStan level raised from 6 to 8.

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

[Unreleased]: https://github.com/NyonCode/laravel-pwa/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/NyonCode/laravel-pwa/releases/tag/v0.1.0
