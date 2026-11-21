# AGENTS.md

Instructions for AI coding agents working **on this repository** (the `nyoncode/laravel-pwa`
package itself). `CLAUDE.md` points here.

## Which doc do you need?

- **Using the package** in an application → [ai/AGENTS.md](./ai/AGENTS.md). It ships with the
  package and is what agents in consuming apps read from `vendor/`; keep it accurate.
- **Working on the package** → this file, [ai/README.md](./ai/README.md) and
  [docs/testing.md](./docs/testing.md).

## What this is

A Laravel 12/13 package (PHP 8.2+) built on `nyoncode/laravel-package-toolkit`
(`PwaServiceProvider::configure(Packager $packager)`). Short name `pwa`.

- `src/Pwa.php` — state, client config, kill switch, CSP hashes (facade `Facades\Pwa`).
- `src/Manifest/` — `ManifestBuilder` (fluent API) and `DisplayStrategy` (override|adaptive|fixed).
- `src/ServiceWorker/ServiceWorkerBuilder.php` — builds `/sw.js` = config JSON + `importScripts` +
  `resources/sw/runtime.js`; `pwa:build` writes it to `bootstrap/cache/pwa/sw.js`.
- `src/Http/` — `AssetController` (manifest, sw, client, ping), `SessionController` (session,
  offline, telemetry), middleware `PwaWebMiddleware` (web group), `RememberStandalone`
  (`pwa.remember`), `CachePage` (`pwa.cache`).
- `resources/js/pwa.js` — browser client (`window.Pwa`), no build step.
- `resources/sw/runtime.js`, `kill-switch.js` — service worker.
- `resources/views/` — `offline`, `maintenance`, `components/{head,nav,install}`.
- `ai/` — agent guide, skill, MCP server; `bin/pwa-ai` — installer.

### How PHP and the service worker talk

The server and `runtime.js` coordinate only through response headers, so a change on one side
usually needs a matching change on the other:

- `X-PWA-Cache: allow` (set by `pwa.cache`) — the only way an HTML page gets cached by the worker.
- `X-PWA-User` (hash, never the ID) and `X-PWA-Logout` (from `PwaWebMiddleware` / `MarkLogout`) —
  separate the cache per user and wipe it on logout.
- `X-PWA-Display` (sent by `pwa.js`) — tells `RememberStandalone` the app is installed.
- `X-PWA-Queued` — marks a replayed request from the worker's offline queue.

Routes (`routes/web.php`): manifest, `sw.js`, `client.js` and `ping` sit **outside** the `web` group
(no session or cookies, so they stay cacheable). `session`, `telemetry` and `offline` sit inside it.

Code comments, docs and `composer.json` are in Czech; `AGENTS.md` and `ai/` are in English.

## Commands

```bash
composer check             # Pint --test, Rector --dry-run, PHPStan, Pest, MCP self-test
composer test              # Pest + Testbench (CI: vendor/bin/pest --ci; serial, tests share testbench paths)
vendor/bin/pest tests/Feature/ManifestTest.php      # one file
vendor/bin/pest --filter="serves a valid manifest"  # one test
composer test:coverage     # pcov, fails below 90 %
composer analyse           # PHPStan/Larastan level 8, src only
composer format            # Pint (required: the MCP server parses Pint-formatted source)
vendor/bin/rector          # apply Rector refactorings (CI only checks)
npm run test:js            # node:test: tests/js/sw (runtime.js) and tests/js/client (pwa.js)
node --test tests/js/sw/runtime.test.mjs            # one JS file
npm run lint:js            # ESLint
npm run test:e2e           # Playwright (Chromium + Firefox) against the workbench, starts testbench serve
vendor/bin/testbench serve --port=8123              # workbench demo app
php anvil list             # package generators (make:command, make:controller, …)
```

JS tests run the real source files: `tests/js/support/sw-env.mjs` evaluates `resources/sw/runtime.js`
in a `vm` context with in-memory Cache Storage, a scripted `fetch` and fake IndexedDB;
`tests/js/support/client-env.mjs` runs `resources/js/pwa.js` in happy-dom. Offline e2e specs are
tagged `@offline` and run in Chromium only (Playwright's Firefox offline mode skips worker requests).

CI: `tests.yml` (Pest on PHP 8.2–8.5 × Laravel 12/13 × lowest/stable, Laravel 13 skipped on 8.2,
plus coverage), `static.yml` (PHPStan, Pint, Rector, `composer audit`, MCP self-test), `js.yml`
(ESLint + unit tests on Node 20/22/24), `e2e.yml` (Playwright), `release.yml` (a `v*` tag creates the
GitHub release from the matching `CHANGELOG.md` section).

`tests/Feature/AiTest.php` fails when a command, config section or `window.Pwa` member is not
mentioned in `ai/AGENTS.md`, so update that file whenever you add one.

## Conventions

- Push notifications are **out of scope**. Do not add VAPID, subscriptions or sending; the only
  push surface is `config('pwa.sw.import')`.
- Nothing HTML is cached without `X-PWA-Cache: allow`. Do not change that default.
- Never send `Clear-Site-Data: "storage"` — it unregisters the worker and kills push subscriptions.
- Offline and maintenance views stay self-contained (inline CSS/SVG/JS). The maintenance script
  must not contain PHP data (CSP hash stability) — use `data-*` attributes.
- Every public change updates `docs/` and `ai/AGENTS.md` in the same commit; new doc pages must be
  listed in `docs/nav.json`.
- Pint formatting is required (the MCP server parses source with regexes that rely on it).
- User-facing strings go through `lang/{cs,en}/messages.php` (`pwa::messages.*`).
- Do not attribute commits to any AI tool/vendor in commit messages.
