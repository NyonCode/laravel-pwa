---
title: Testing
description: How to test your app with the package (helpers and assertions) and how to develop and test the package itself (Pest, JS tests, Playwright, CI).
---

# Testing

## In your app

### Helpers and assertions

The package adds assertions to `TestResponse` (it registers them itself) and an `InteractsWithPwa`
trait for requests coming from the installed app:

```php
// tests/TestCase.php
use NyonCode\LaravelPwa\Testing\InteractsWithPwa;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithPwa;
}
```

```php
it('allows the dashboard offline, but never the billing page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertPwaCacheable()->assertPwaUser($user);
    $this->actingAs($user)->get('/billing')->assertNotPwaCacheable();
});

it('remembers users of the installed app', function () {
    $user = User::factory()->create();

    $this->asInstalledApp()
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertCookie(Auth::guard()->getRecallerName());
});

it('tells the worker about the logout', function () {
    $this->actingAs(User::factory()->create())->post('/logout')->assertPwaLogout();
});

it('serves an installable manifest', function () {
    $this->get('/manifest.webmanifest')->assertValidManifest();
});
```

| Helper | What it checks / does |
| --- | --- |
| `asInstalledApp($mode = 'standalone')` | sends the `X-PWA-Display` header and the `pwa_display` cookie like the installed app |
| `asBrowserTab()` | the same with the `browser` mode |
| `assertPwaCacheable()` | `X-PWA-Cache: allow` and no `Cache-Control: no-store` |
| `assertNotPwaCacheable()` | the page must not go into the offline cache |
| `assertPwaLogout()` | `X-PWA-Logout: 1` and `Clear-Site-Data` without `"storage"` |
| `assertPwaUser($user)` | `X-PWA-User` matches the user (`null` = `guest`) |
| `assertValidManifest($strict = false)` | the manifest is installable (`name`, `start_url`, `display`, 192 and 512 icons); with `strict`, also free of warnings (maskable icon, `id`, `short_name` length, colors) |

The manifest check is also available on its own: `app(ManifestValidator::class)->validate($manifest)`
returns `['errors' => [...], 'warnings' => [...]]`. Test the package events with `Event::fake()`
([Events](events.md#testing)) and push notifications with `WebPush::fake()`
([Push notifications](push.md#testing)).

### Doctor in CI

```yaml
- run: npm run build
- run: php artisan pwa:build
- run: php artisan pwa:doctor --ci
```

### In the browser

Pest 4 browser tests or Playwright: `context.setOffline(true)`, navigate to a page with `pwa.cache`
and expect the cached content; navigate elsewhere and expect the offline screen.

## Developing the package

```bash
composer install && npm install
composer check           # Pint, Rector, PHPStan (level max), Pest, MCP self-test
composer test:coverage   # Pest with coverage (pcov), fails below 90%
npm run test:js          # service worker and client unit tests (node:test)
npm run lint:js          # ESLint
npm run test:e2e         # Playwright (Chromium, Firefox, WebKit, iPhone) against the workbench
```

A single test: `vendor/bin/pest --filter="name"` or `node --test tests/js/sw/runtime.test.mjs`.

### Workbench

`workbench/` is a demo app: `/` and `/orders` with `pwa.cache`, `/private` without caching, and a
form at `/items`.

```bash
vendor/bin/testbench serve --port=8123
```

### Service worker and client tests

`tests/js/sw/` runs the real `resources/sw/runtime.js` in a simulated service worker environment
(`tests/js/support/sw-env.mjs`: in-memory Cache Storage, scripted `fetch`, fake IndexedDB).
It covers precaching, `X-PWA-Cache` consent, user separation and clearing after logout, 503 vs.
offline, image limits and age, network-first API, and the offline queue and its replay with CSRF
refresh.

`tests/js/client/` runs `resources/js/pwa.js` in happy-dom (`tests/js/support/client-env.mjs`):
registration, events, keep-alive and CSRF, retry after 419 on the same origin only, adapters,
install.

### Browser tests

`tests/e2e/pwa.spec.mjs` (`@playwright/test`) starts the workbench itself and checks: service
worker registration, precaching, caching of allowed pages only, the offline screen, the offline
queue and its replay, keep-alive, maintenance mode (503, the cache stays), and `pwa:disable` and
`pwa:enable`. `update.spec.mjs` deploys a new worker and accepts it in `<x-pwa::update-toast/>`,
`push.spec.mjs` delivers a push to the real worker, `ios.spec.mjs` checks the iPhone install steps.

| Project | Runs |
| --- | --- |
| `chromium` | everything except `@ios`; push only here (CDP) |
| `firefox` | everything except `@offline` (Playwright's offline mode skips worker requests) and `@ios` |
| `webkit` | everything except `@ios` (Desktop Safari engine) |
| `iphone` | only `@ios`: iPhone Safari outside the Home Screen app |

Playwright's WebKit build exposes the push API, but `pushManager.getSubscription()` there blocks the
next navigation. The client calls the push API only on devices that subscribed, and the iPhone
project removes `PushManager`, as Safari does outside the Home Screen app.

```bash
npx playwright install chromium firefox webkit
npm run test:e2e
```

### Starter kits

`scripts/starter-kit.sh <livewire|react|vue> [12|13]` creates a fresh Laravel starter kit, installs the
package from this checkout and applies the documented setup (`tests/starter-kits/setup.php`: head
component, `pwa.cache` on the dashboard, maintenance exceptions, push with `HasPushSubscriptions`, icons).
Then it runs `pwa:doctor --ci` and `tests/starter-kits/smoke.spec.mjs` in Chromium against `php artisan
serve`: manifest and worker, sign-in and keep-alive, the dashboard offline and the offline screen, a form
sent offline (queued and replayed in Inertia, "not sent" in Livewire) and cleanup on logout.

```bash
npm run test:kits -- react 13
```

The kits bring their own configs and frontend stacks, which the workbench cannot: the 500 from
`/pwa/session` fixed in 1.0.2 showed up only there. The app is created outside the checkout
(`$TMPDIR/laravel-pwa-kits`) and removed after a passing run (`KIT_KEEP=1` keeps it); a setup step that no longer changes its file fails, so a reshaped kit is
noticed.

### CI

GitHub Actions: `tests.yml` (Pest on PHP 8.2–8.5 × Laravel 12/13 × lowest/stable, plus coverage),
`static.yml` (PHPStan, Pint, Rector, `composer audit`, MCP self-test), `js.yml` (ESLint and unit
tests on Node 20/22/24), `e2e.yml` (Playwright), `starter-kits.yml` (the smoke test in the Livewire, React and Vue kits on Laravel 12 and 13; weekly too), `mutation.yml` (weekly and on demand: Pest mutation
testing of the push, manifest, service worker and HTTP code) and `release.yml` (a `v*` tag creates a
release from `CHANGELOG.md`). `tests/ArchTest.php` keeps the API boundaries: contracts are interfaces,
events are final, the source is strictly typed and classes outside the public API are `@internal`.

### Structure

```text
src/
  Pwa.php                         state, clientConfig, cspHashes, kill switch
  PwaServiceProvider.php          Packager configuration
  Facades/Pwa.php
  Manifest/ManifestBuilder.php    fluent manifest API
  Manifest/DisplayStrategy.php    override | adaptive | fixed
  ServiceWorker/ServiceWorkerBuilder.php
  Http/Controllers/               AssetController, SessionController
  Http/Middleware/                PwaWebMiddleware, RememberStandalone, CachePage
  Listeners/MarkLogout.php
  Console/                        pwa:* commands
resources/
  js/pwa.js                       client (window.Pwa)
  sw/runtime.js, kill-switch.js   service worker
  views/                          offline, maintenance, components/*
ai/                               AGENTS.md, skill, MCP server
docs/                             this documentation (order in nav.json)
```

### Documentation rule

A change to the public API, config, a command or an event = a change in `docs/` **and** in
`ai/AGENTS.md` in the same commit. The MCP server reads signatures from `src/` and the config from
`config/pwa.php` on its own, but not the prose.
