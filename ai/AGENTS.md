# Using nyoncode/laravel-pwa (agent reference)

Audience: an AI agent working **in a Laravel application** that requires `nyoncode/laravel-pwa`.
This is the complete public surface, written from the source of the installed release. It ships
inside the package (`vendor/nyoncode/laravel-pwa/ai/AGENTS.md`), so it matches `composer.lock`.
Prefer it — and the package's MCP server, if registered — over recalling the API.

Requires PHP 8.2+ (8.3+ with Laravel 13) and Laravel 12.x or 13.x. Built on
`nyoncode/laravel-package-toolkit`; short name `pwa` (views `pwa::`, translations `pwa::`, publish
tags `pwa::config`, `pwa::views`, `pwa::translations`).

## Mental model

- **One app, one origin.** Manifest `display: standalone` + `display_override: ["minimal-ui"]`:
  Edge/Chrome on desktop get `minimal-ui` (Back/Reload), phones, tablets and Safari get `standalone`.
- **The package owns the service worker at `/sw.js`.** It is a route, not a file in `public/`.
  Never create `public/sw.js` or a second service worker on scope `/`. Extra worker code (push,
  Sentry) goes through `config('pwa.sw.import')` → `importScripts`; custom caching routes go
  through `config('pwa.sw.extend')` and `self.PwaWorker`.
- **Nothing HTML is cached unless the server allows it** with the `pwa.cache` middleware.
- **Push notifications are NOT implemented by this package.** It only provides the slot
  (`sw.import`), install state and events. Do not add VAPID/subscription code to this package's
  config; build it in the app or a separate push package.
- **Login persistence in the installed app** relies on the server-set HttpOnly remember cookie
  (`pwa.remember` middleware), never on localStorage.

## Installation checklist

```bash
composer require nyoncode/laravel-pwa
php artisan pwa:install                       # publishes config/pwa.php
php artisan pwa:icons resources/pwa/icon.png  # gd for PNG, imagick for SVG
```

```blade
{{-- layout <head> --}}
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<x-pwa::head/>
```

```php
// routes/web.php
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('pwa.remember');
Route::middleware(['auth', 'pwa.cache'])->group(fn () => Route::get('/dashboard', DashboardController::class));

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->preventRequestsDuringMaintenance(except: ['manifest.webmanifest', 'sw.js', 'pwa/*']);
})
```

Deploy: `npm run build` → `php artisan pwa:build` (also run by `php artisan optimize`) →
`php artisan pwa:doctor --ci`.

## Routes registered by the package

| Method | URI | Name | Middleware | Purpose |
|---|---|---|---|---|
| GET | `/manifest.webmanifest` | `pwa.manifest` | none | manifest JSON, ETag/304 |
| GET | `/sw.js` (`pwa.sw.url`) | `pwa.sw` | none | service worker (or kill switch) |
| GET | `/pwa/client.js` | `pwa.client` | none | client ES module, immutable |
| GET | `/pwa/ping` | `pwa.ping` | none | 204 connectivity probe |
| GET | `/pwa/session` | `pwa.session` | web | keep-alive: `{authenticated, user, csrf, lifetime}` |
| POST | `/pwa/telemetry` | `pwa.telemetry` | web, throttle:30,1, no CSRF | service-worker error log |
| GET | `/offline` (`pwa.offline.route`) | `pwa.offline` | web | offline screen |

The `pwa` prefix is `config('pwa.route_prefix')`.

## Middleware

| Alias / group | Class | Effect |
|---|---|---|
| `pwa.remember` | `Http\Middleware\RememberStandalone` | when `isStandaloneClient()` merges `remember=on` (field `pwa.session.remember_field`) into the request, unless the field was sent explicitly falsy (`0`/`false`/`off`); put it on the **login POST** route |
| `pwa.cache` | `Http\Middleware\CachePage` | adds `X-PWA-Cache: allow` to GET 200 responses → service worker may store the page for offline |
| appended to `web` | `Http\Middleware\PwaWebMiddleware` | marks session from `?source=pwa`; sends `X-PWA-User` (HMAC hash of user id); after logout sends `X-PWA-Logout: 1` + `Clear-Site-Data: "cache"` |

Logout detection is the `Illuminate\Auth\Events\Logout` listener `Listeners\MarkLogout` — nothing
to wire.

## Facade `NyonCode\LaravelPwa\Facades\Pwa` (→ `NyonCode\LaravelPwa\Pwa`, singleton)

| Method | Returns | Notes |
|---|---|---|
| `enabled()` | bool | `config('pwa.enabled')` and not killed |
| `killed()` / `disable()` / `enable()` | bool / void | kill switch flag in cache store `pwa.sw.kill_switch_store` |
| `manifest()` | `ManifestBuilder` | fluent manifest API (singleton) |
| `serviceWorker()` | `ServiceWorkerBuilder` | build/compile the worker |
| `isStandaloneRequest(?Request)` | bool | header `X-PWA-Display`, cookie `pwa_display`, `?source=pwa`, or session `pwa.standalone` |
| `isStandaloneClient(?Request)` | bool | header `X-PWA-Display` or cookie `pwa_display` only; `?source=pwa` and the session flag can be planted by a link, so only this one drives `pwa.remember` |
| `userHash(?Request)` | string | `guest` or 16-char HMAC of the auth id |
| `clientConfig()` | array | JSON passed to the client in `#pwa-config` |
| `cspNonce()` | ?string | `Vite::cspNonce()` |
| `cspHashes(string $view = 'pwa::maintenance')` | `['script-src' => [...], 'style-src' => [...]]` | SHA-256 hashes of the prerendered maintenance screen |

## `ManifestBuilder` — call in `AppServiceProvider::boot()`

```php
Pwa::manifest()
    ->shortcut(string $name, string $url, ?string $icon = null, ?string $description = null)
    ->shareTarget(string $action, string $method = 'POST', array $accept = [], string $filesField = 'files')
    ->fileHandler(string $action, array $accept, ?string $name = null)   // ['text/csv' => ['.csv']]
    ->protocolHandler(string $protocol, string $url)                      // 'web+orders', '/orders/%s'
    ->windowControlsOverlay()                                             // prepends to display_override
    ->tabbed(array $tabStrip = [])                                        // prepends 'tabbed'
    ->launchHandler(string|array $clientMode)
    ->name(string) ->shortName(string) ->description(string) ->id(string) ->startUrl(string)
    ->scope(string) ->themeColor(string) ->backgroundColor(string)
    ->icon(string $src, string $sizes, ?string $type = null, string $purpose = 'any') // replaces pwa:icons files
    ->locale(string)                                                      // translations, lang, dir
    ->private() ->vary(string ...$headers)                                // manifest Cache-Control / Vary
    ->set(string $key, mixed $value);                                     // any member, wins over config
```

Also `toArray(?Request)`, `toJson(?Request)`, `icons()`, `flush()`, `forRequest(Request)` (copy with
resolvers applied), `parameters(Request $page)`, `url(Request $page)` (manifest link with
parameters), `cachePolicy()`. Merge order: config → display strategy → existing icons →
`*_localized` (config `locales`) → boot-time fluent calls → per-request resolvers; null/''/[] dropped.

### Per-request resolvers (tenants, branding, locale)

```php
// closure; the optional second closure gives the manifest link parameters
Pwa::manifest()->resolveUsing(
    fn (Request $request, ManifestBuilder $m) => $m->name('Team '.$request->query('team')),
    fn (Request $page) => ['team' => $page->user()->team->slug],
);
// or a class (container-resolved), also via config pwa.manifest.resolvers
final class TenantManifest implements ManifestResolver, ManifestParameters { /* resolve(), parameters() */ }
```

`NyonCode\LaravelPwa\Contracts\ManifestResolver::resolve(Request $request, ManifestBuilder $manifest): void`
runs on `/manifest.webmanifest` on a clone (Octane-safe). That route has **no session or auth**:
anything page-specific must come through `ManifestParameters::parameters(Request $page): array<string,string>`,
which `<x-pwa::head/>` appends to the manifest link (render time, with session). Query values are user
input: validate them (whitelist, lookup). Give each tenant its own `id`. `<x-pwa::head/>` takes
`theme-color` and the iOS title from the resolved manifest, so resolvers also run on every page
render: keep them cheap. Built-in `Manifest\LocaleResolver` (active when `pwa.locales` is set) adds
`?lang={page locale}` and builds the manifest in it; only locales listed in `pwa.locales` are
accepted (list the default too). A throwing resolver shows up in `pwa:doctor`.
Icons are listed only if the files exist in `public/{pwa.icons.path}` (`icon-192.png`,
`icon-512.png`, `maskable-512.png`, `icon.svg`).

A share-target route receives a POST from the OS: exclude it from CSRF
(`->withoutMiddleware(ValidateCsrfToken::class)`).

## Display strategy (`pwa.display.strategy`)

| Value | Manifest | Notes |
|---|---|---|
| `override` (default) | `display: value`, `display_override: override` | same JSON for everyone, cacheable |
| `adaptive` | `display: desktop` for Windows/macOS/Linux/ChromeOS (Client Hints) or Windows/Linux/CrOS UA, else `value` | sends `Vary` + `Accept-CH`, private cache |
| `fixed` | `display: value` | |

Safari reports Mac even on iPad; without Client Hints macOS is therefore not treated as desktop.

## Blade components (anonymous, namespace `pwa`)

- `<x-pwa::head/>` — manifest link, theme-color (light/dark), apple meta, apple-touch-icon if the
  file exists, `<script type="application/json" id="pwa-config">`, `<script type="module"
  src="/pwa/client.js?v=hash" nonce>`.
- `<x-pwa::nav/>` — Back/Reload buttons, shown only on desktop (`pointer: fine`, width ≥
  `desktop_nav.min_width`) in `standalone` or `window-controls-overlay`; hidden in `minimal-ui`
  (browser draws them) and on phones. Prop `start-url`.
- `<x-pwa::install>Text</x-pwa::install>` — hidden `[data-pwa-install]` button + hidden
  `[data-pwa-ios-hint]`; client reveals them when installable, visits ≥ `install.min_visits`, not
  dismissed within `install.dismiss_days`. Prop `dismissible` (default true). Any element with
  `data-pwa-install` works as an install trigger; `data-pwa-install-dismiss` dismisses.

Views to customise (`php artisan vendor:publish --tag="pwa::views"`): `offline.blade.php`,
`maintenance.blade.php`, `components/{head,nav,install}.blade.php`. Offline and maintenance pages
must stay self-contained (inline CSS/SVG/JS, no external URLs). In `maintenance.blade.php` pass PHP
data only via `data-*` attributes on `#pwa-maintenance`, never inside `<script>` (keeps the CSP hash
stable). Keep the `@if($nonce) nonce=…` attributes.

## Service worker behaviour (`resources/sw/runtime.js`)

Requests are handled in this order; the first row that applies wins.

| Request | Handling | Cache name |
|---|---|---|
| `/sw.js`; navigations to `cache.never_cache` paths | untouched | — |
| fetch/XHR to `cache.never_cache` paths | passed to the network, never stored or queued; identity headers are read | — |
| POST/PUT/PATCH/DELETE (`fetch`/XHR, not navigations) | network; on network error stored in IndexedDB `pwa`/`queue`, responds `202 {"queued":true}` + `X-PWA-Queued: 1` | — |
| navigation | network (+ Navigation Preload); after `cache.navigation_timeout` s use cache if present; on error cache, then `/offline` | `pwa-pages-{userHash}` |
| `/build/*`, precache list | cache-first | `pwa-static-{version}` |
| routes registered in `sw.extend` via `self.PwaWorker.route()` | the app's handler | handler's choice |
| other same-origin GET | first matching cache rule (see below) | `pwa-rule-{name}-{userHash}` or `pwa-shared-{name}` |
| other origins without an `sw.extend` route | untouched | — |

Never cached: non-200, `Cache-Control: no-store`, 503, HTML without `X-PWA-Cache: allow`.
503 navigations are shown as-is (maintenance screen), never replaced by the offline page.
Identity headers are read from every same-origin response (navigations, mutations, rule responses,
`never_cache` pass-through), so XHR logins/logouts of Inertia and Livewire are seen; responses of
other origins never change the identity, and a per-user cache only stores responses whose
`X-PWA-User` matches its owner. The client also posts `IDENTITY` from each keep-alive.
User change (`X-PWA-User`) or logout (`X-PWA-Logout`) purges that user's `pwa-pages-*` and
`pwa-rule-*` caches and the queue; `pwa-shared-*` caches survive. Version = hash of runtime config
(incl. rules) + precache list + `sw.import`/`sw.extend` contents + runtime file. New versions wait;
the client asks the user, then sends `SKIP_WAITING`. Activation removes caches of rules that no
longer exist and 0.1.x caches (`pwa-api-*`, `pwa-images*`).

Messages to the worker: `SKIP_WAITING`, `REPLAY_QUEUE`, `CLEAR_USER`, `IDENTITY` (`{user}` from keep-alive), `GET_VERSION` (with a port).
Queue replay retries once with a fresh CSRF token after 419 and stops on network error or 503.

### Cache rules (`pwa.cache.rules`, `Pwa::serviceWorker()->cache()`)

Order: `cache.rules` config → fluent rules → `cache.api` (network-first, cache `api`) → default
image/font rule (`cache.images`: stale-while-revalidate, `max_entries`, `max_age`; disable with
`'enabled' => false`). Rules match same-origin GET that are not navigations; `match` is a path
with `*` (query string ignored for matching, each URL stored separately), `destination` limits to
`Request.destination`.

```php
// config/pwa.php → 'cache' => ['rules' => [...]]
['match' => '/api/catalog*', 'strategy' => 'stale-while-revalidate', 'cache' => 'catalog', 'max_entries' => 200, 'max_age' => 86400],
['match' => '/avatars/*', 'destination' => 'image', 'strategy' => 'cache-first', 'max_age' => 604800],
['match' => '/api/stock', 'strategy' => 'network-first', 'timeout' => 2],

// or fluent, in AppServiceProvider::boot()
Pwa::serviceWorker()->cache('/api/catalog*')->staleWhileRevalidate()->name('catalog')->maxEntries(200)->maxAge(days: 1);
Pwa::serviceWorker()->cache(null)->destination('font')->cacheFirst()->maxAge(days: 365);
```

| `CacheRule` method | Config key | Notes |
|---|---|---|
| `networkFirst(?int $timeout)`, `cacheFirst()`, `staleWhileRevalidate()`, `networkOnly()`, `cacheOnly()`, `strategy(CacheStrategy\|string)` | `strategy` | default network-first |
| `name(string)` | `cache` | `[a-z0-9-]`, ≤ 40 chars; derived from the rule when omitted |
| `maxEntries(int)` | `max_entries` | oldest entries removed first |
| `maxAge(seconds, minutes, hours, days)` | `max_age` (s) | older copies are not served (network-first: not even as fallback; cache-first/SWR: only offline) |
| `timeout(int)` | `timeout` (s) | network-first only, else `InvalidArgumentException` |
| `shared(bool)` | `shared` | one cache for all users; stores only responses without `Cache-Control: private` and with `X-PWA-User` missing/`guest` |
| `destination(string ...)` | `destination` | `image`, `font`, `style`, `script`, `audio`, `video`, … |

Invalid rules throw `InvalidArgumentException` naming the config index (`pwa.cache.rules.1: …`);
`pwa:build` fails and `pwa:doctor` reports them. One name for a shared and a per-user rule is an error.

### `sw.extend` and `self.PwaWorker`

`config('pwa.sw.extend')` scripts load after the runtime (`sw.import` loads before it). They get the
frozen `self.PwaWorker`: `route(match, handler)` (match = path pattern on the same origin, `RegExp`
on the full URL, or `({url, request, event}) => bool`; handler returns a `Response`), `strategies`
(`networkFirst`, `cacheFirst`, `staleWhileRevalidate`, `networkOnly`, `cacheOnly`, each
`(event, {cacheName, maxEntries, maxAge, timeout, shared})`), `cacheName(name, {shared})` →
`pwa-rule-{name}-{user}` or `pwa-shared-{name}`, `currentUser()`, `broadcast(message)`, `version`,
`config`. Routes never see navigations, mutations or `never_cache` paths.

```js
// public/pwa-extend.js
const { route, strategies, cacheName } = self.PwaWorker;
route('/reports/*', async (event) => strategies.cacheFirst(event, { cacheName: await cacheName('reports'), maxAge: 3600 }));
```

## Client `window.Pwa` (`resources/js/pwa.js`)

`config`, `displayMode()`, `isInstalled()`, `isIos()`, `install()` → `'accepted'|'dismissed'|'unavailable'`,
`update()`, `keepAlive()`, `version()`, `on(name, fn)` → unsubscribe fn, `badge.set(n)`, `badge.clear()`.

DOM events on `document` (`pwa:` + name, also via `Pwa.on(name)`): `ready`, `resume`, `online`,
`offline`, `maintenance`, `logout`, `queue` `{count}`, `replayed` `{url,status}`,
`update-available` `{worker}`, `activated` `{version}`, `installed`, `csrf` `{token}`,
`csrf-refreshed`.

On boot the client sets cookie `pwa_display`, wraps `window.fetch` and `window.axios` to retry a
non-GET same-origin 419 once with a fresh token, registers or unregisters the worker, runs keep-alive on
`visibilitychange`/`pageshow` and every `session.keepalive_minutes`. Fresh CSRF tokens are written
to `meta[name=csrf-token]`, `input[name=_token]`, axios defaults and `livewireScriptConfig`.

Adapters: Livewire 3 (`Livewire.hook('request')` fail: 503 → reload, 419 → refresh token, offline →
bar) and Inertia (`inertia:invalid` 503 → reload, queued 202 → bar; `inertia:exception` offline).

## Events (`NyonCode\LaravelPwa\Events`, Laravel `Dispatchable`)

| Event | When | Properties |
|---|---|---|
| `ManifestResolving` | every manifest request, after the resolvers | `request`, `manifest` (per-request copy, mutable) |
| `ServiceWorkerBuilt` | `pwa:build` / `ServiceWorkerBuilder::write()` | `version`, `path`, `precache` |
| `KillSwitchActivated` / `KillSwitchDeactivated` | `pwa:disable` / `pwa:enable` (`Pwa::disable()` / `enable()`) | — |
| `TelemetryReceived` | `POST /pwa/telemetry` validated | `source` (`sw`\|`client`), `events` (type, message, stack, version, display), `userAgent` |

`pwa:down` wraps `artisan down`: use Laravel's `MaintenanceModeEnabled` / `MaintenanceModeDisabled`.
Telemetry is logged by the `LogTelemetry` listener (`pwa.telemetry.channel`); set
`pwa.telemetry.log = false` when you handle `TelemetryReceived` yourself.

## Testing an application

`use NyonCode\LaravelPwa\Testing\InteractsWithPwa;` in `tests/TestCase.php` gives
`asInstalledApp(string $mode = 'standalone')` (header `X-PWA-Display` + cookie `pwa_display`) and
`asBrowserTab()`. TestResponse macros (registered by the package): `assertPwaCacheable()`,
`assertNotPwaCacheable()`, `assertPwaLogout()`, `assertPwaUser(Authenticatable|string|null $user)`
(`null` = `guest`), `assertValidManifest(bool $strict = false)` (errors: name, start_url, display,
192/512 "any" icons; strict adds warnings: maskable, id, short_name ≤ 12, colours).
`app(Manifest\ManifestValidator::class)->validate(array $manifest)` returns
`['errors' => [...], 'warnings' => [...]]`. There is no marker for replayed offline-queue requests.

```php
$this->asInstalledApp()->post('/login', $credentials)->assertCookie(Auth::guard()->getRecallerName());
$this->actingAs($user)->get('/dashboard')->assertPwaCacheable()->assertPwaUser($user);
$this->get('/manifest.webmanifest')->assertValidManifest();
```

## Artisan commands

| Command | Notes |
|---|---|
| `pwa:install` | publish config, print next steps |
| `pwa:icons {source?} {--background=}` | writes `icon-{size}.png`, `maskable-512.png`, `apple-touch-icon.png`, `favicon-32.png` (+ `icon.svg`) |
| `pwa:build` | writes `{sw.compiled_path}/sw.js`; run after `npm run build`; hooked to `optimize` |
| `pwa:clear` | deletes compiled worker; hooked to `optimize:clear` |
| `pwa:doctor {--ci}` | checks; `--ci` exits 1 on errors |
| `pwa:down {--message=} {--until=} {--retry=} {--refresh=} {--secret=} {--with-secret}` | `artisan down --render=pwa::maintenance` |
| `pwa:disable {--force}` / `pwa:enable` | kill switch: `/sw.js` serves a worker that deletes `pwa-*` caches and unregisters |

## Config `config/pwa.php` (top-level keys)

`enabled`, `name`, `short_name`, `description`, `id`, `start_url`, `scope`, `display.{strategy,value,override,desktop}`,
`orientation`, `desktop_nav.{enabled,min_width}`, `theme.{light,dark,accent_dark,background}`,
`icons.{source,path,maskable_padding,sizes}`, `screenshots`, `categories`, `shortcuts`,
`share_target`, `file_handlers`, `protocol_handlers`, `launch_handler`, `scope_extensions`,
`locales`, `manifest.resolvers`, `sw.{url,import,extend,vite_manifest,build_directory,compiled_path,kill_switch_store}`,
`cache.{navigation_timeout,rules,api,images,never_cache}`, `offline.{enabled,route,precache,queue}`,
`session.{standalone_remember,keepalive_minutes,refresh_csrf,remember_field}`,
`maintenance.{message,until,retry,refresh}`, `install.{min_visits,dismiss_days,ios_hint}`,
`telemetry.{enabled,channel,max_events}`, `adapters.{livewire,inertia,reload_on_resume}`,
`route_prefix`. Use the MCP tool `describe_config` for defaults and comments.

Env: `PWA_ENABLED` (default: true unless `APP_ENV=local`), `PWA_NAME`, `PWA_SHORT_NAME`,
`PWA_DESCRIPTION`, `PWA_DISPLAY_STRATEGY`, `PWA_KILL_SWITCH_STORE`, `PWA_LOG_CHANNEL`.

## Common mistakes (all silent)

1. **`public/sw.js` or `public/manifest.webmanifest` exists** → served instead of the routes; kill
   switch stops working. Delete them.
2. **Second service worker registered by the app or a push library on `/`** → replaces this one.
   Use `sw.import`.
3. **`pwa.cache` missing** → nothing is available offline. It is opt-in by design.
4. **`pwa.cache` on sensitive pages** (payments, admin) → stored on the device (per user, purged on
   logout, but still stored). Prefer `Cache-Control: no-store` there.
5. **`pwa.remember` on the GET login page instead of the POST** → no effect.
6. **`pwa:build` before `npm run build`** → stale precache; `pwa:doctor` reports "not current".
7. **Changing `id`** → browsers treat it as a new app.
8. **Classic (non-fetch) form POST offline** → not queued; only fetch/XHR mutations are.
9. **PHP data inside `<script>` of a customised maintenance view** → CSP hash changes on every render.
10. **`Clear-Site-Data: "storage"` added by the app on logout** → unregisters the worker and kills
    push subscriptions. The package sends only `"cache"` on purpose.
11. **Session driver `array`/`cookie` or no shared cache store on multiple servers** → logouts,
    kill switch not seen by all servers.
12. **Expecting push from this package** → there is none; see `docs/push.md`.
13. **Inertia `useForm({ remember: false })` on login** → the explicit `false` wins over `pwa.remember`,
    so the installed app is not remembered. Default it to `window.Pwa?.isInstalled() ?? false`.
14. **`shared: true` on personal data** → the worker refuses `Cache-Control: private` and signed-in
    responses, so nothing is cached offline. Keep personal API rules per user (the default).
15. **A broad rule before a narrow one** (`/api/*` before `/api/live`) → the first match wins; put the
    narrow rule (often `network-only`) first.

## Before you call the work done

1. `php artisan pwa:doctor` — no errors.
2. `php artisan route:list --path=pwa` and `curl -s /manifest.webmanifest | jq` — routes and
   manifest as expected.
3. `curl -I /some-offline-page` shows `X-PWA-Cache: allow` where intended, and only there.
4. `npm run build && php artisan pwa:build` — then `pwa:doctor` says the compiled worker is current.
5. In a browser: DevTools → Application → Service workers shows `/sw.js` activated; offline
   (Network → Offline) a cached page loads and an uncached one shows the offline screen.

## Tooling for agents

| What | Where | Install |
|---|---|---|
| This guide, referenced from the app's `AGENTS.md` | `ai/AGENTS.md` | `vendor/bin/pwa-ai install` |
| Claude Code skill `laravel-pwa` | `ai/skills/laravel-pwa/SKILL.md` | same command |
| MCP server (Node 18+, no deps) | `ai/mcp/server.mjs` | same command, writes `.mcp.json` |

MCP tools: `search_docs`, `list_docs`, `get_doc`, `list_api`, `describe_api`, `describe_config`,
`list_commands`, `describe_client`. Self-test: `node vendor/nyoncode/laravel-pwa/ai/mcp/server.mjs --self-test`.
Human documentation (Czech): `vendor/nyoncode/laravel-pwa/docs/`.
