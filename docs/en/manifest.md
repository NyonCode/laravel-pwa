---
title: Manifest
description: How /manifest.webmanifest is built, the Pwa::manifest() fluent API, resolvers for tenants and locales, icons, localization and OS integration.
---

# Manifest

`GET /manifest.webmanifest` is assembled by `ManifestBuilder` from your config and from calls in
your application code. The response has `Content-Type: application/manifest+json`, an `ETag` (304
on a match) and `Content-Language`, and it is cached for an hour (`private` when a resolver or the
adaptive display strategy requires it).

## Build order

1. Config (`config/pwa.php`).
2. Display strategy (`display`, `display_override`).
3. Icons that exist in `public/{icons.path}`.
4. Localized members (`locales`).
5. Fluent `Pwa::manifest()->…` calls made during boot.
6. Per-request [resolvers](#resolvers-tenants-and-branding) (they win).
7. Empty values (`null`, `''`, `[]`) are omitted.

## Fluent API

Call it in `AppServiceProvider::boot()`:

```php
use NyonCode\LaravelPwa\Facades\Pwa;

Pwa::manifest()
    ->shortcut('New order', '/orders/create', icon: '/pwa/icons/new.png', description: 'Quick create')
    ->shareTarget('/share', 'POST', ['image/*', 'application/pdf'], filesField: 'files')
    ->fileHandler('/open', ['text/csv' => ['.csv']], name: 'CSV')
    ->protocolHandler('web+order', '/orders/%s')
    ->windowControlsOverlay()
    ->tabbed(['home_tab' => ['scope_patterns' => [['pathname' => '/']]]])
    ->launchHandler(['navigate-existing', 'auto'])
    ->screenshot('/pwa/screenshots/desktop.png', '1280x800', 'wide', 'Dashboard')
    ->category('business', 'productivity');
```

| Method | Result in the manifest | Where it works |
| --- | --- | --- |
| `shortcut(name, url, icon?, description?, iconSizes = '96x96')` | adds to `shortcuts` | Android, Chromium desktop |
| `shareTarget(action, method, accept, filesField)` | `share_target` (GET, POST, `multipart/form-data` with files) | Android, ChromeOS, Windows |
| `fileHandler(action, accept, name?)` | adds to `file_handlers` | Chromium desktop |
| `protocolHandler(protocol, url)` | adds to `protocol_handlers` | Chromium desktop |
| `windowControlsOverlay()` | `window-controls-overlay` at the start of `display_override` | Chromium desktop |
| `tabbed(tabStrip)` | `tabbed` in `display_override`, optionally `tab_strip` | Chromium desktop |
| `launchHandler(clientMode)` | `launch_handler.client_mode` | Chromium desktop |
| `screenshot(src, sizes, formFactor?, label?, type = 'image/png')` | adds to `screenshots` | Chromium install dialog |
| `category(...categories)` | adds to `categories` | store listings |
| `set(key, value)` | any member | – |
| `toArray(request?)`, `toJson(request?)` | the result | – |
| `icons()` | list of existing icons | – |
| `flush()` | discards the fluent settings (tests) | – |

### Share target

The route the share target points to must accept a POST without CSRF (the share comes from the
operating system):

```php
Route::post('/share', ShareController::class)
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
```

Fields: `title`, `text`, `url`, and the files in `files`. The share arrives as a POST navigation, which
the worker leaves to the network (only fetch/XHR mutations go to the [offline queue](offline.md)), so
sharing needs a connection. Redirect after the POST, so a reload does not share twice:

```php
class ShareController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $note = $request->user()->notes()->create($request->only('title', 'text', 'url'));

        foreach ($request->file('files', []) as $file) {
            $note->addAttachment($file);
        }

        return to_route('notes.show', $note); // 303 after the POST
    }
}
```

`pwa:doctor` and `assertValidManifest()` warn when `action` is outside the scope, or when files are
shared without `POST` and `multipart/form-data`.

### File handlers

A file opened from the OS arrives through `launchQueue`:

```js
if ('launchQueue' in window) {
  launchQueue.setConsumer(async ({ files }) => {
    for (const handle of files) importCsv(await handle.getFile());
  });
}
```

## Resolvers (tenants and branding)

A resolver adjusts the manifest for a specific request: a different name, colors, icons or
`start_url` for each tenant or brand. It runs on a copy of the builder, so nothing leaks between
requests (Octane).

```php
// AppServiceProvider::boot()
Pwa::manifest()->resolveUsing(function (Request $request, ManifestBuilder $manifest): void {
    if ($request->getHost() === 'shop.example.com') {
        $manifest->name('Shop')->themeColor('#e11d48')->icon('/brand/shop.svg', 'any')->vary('Host');
    }
});
```

Typed methods: `name()`, `shortName()`, `description()`, `id()`, `startUrl()`, `scope()`,
`themeColor()`, `backgroundColor()`, `icon($src, $sizes, $type = null, $purpose = 'any')`,
`locale()`. Once a resolver adds an icon, the icons from `pwa:icons` are not listed.
`private()` sends `Cache-Control: private`, and `vary('Host')` sends a `Vary` header.

### Manifest link parameters

The manifest route runs **without session and cookies** (so it can be cached), so a resolver there
cannot see the signed-in user or a tenant stored in the session. Pass what it needs in the URL: the
class also implements `ManifestParameters`, and `<x-pwa::head/>` adds the parameters to
`<link rel="manifest">`.

```php
use NyonCode\LaravelPwa\Contracts\ManifestParameters;
use NyonCode\LaravelPwa\Contracts\ManifestResolver;

final class TenantManifest implements ManifestParameters, ManifestResolver
{
    // On the page (with a session): which parameters to add to the link.
    public function parameters(Request $page): array
    {
        return ['tenant' => tenant()->slug];
    }

    // On /manifest.webmanifest?tenant=acme: the parameter is user input, so validate it.
    public function resolve(Request $request, ManifestBuilder $manifest): void
    {
        $tenant = Tenant::where('slug', $request->query('tenant'))->first();

        if ($tenant) {
            $manifest->name($tenant->name)
                ->id("/?tenant={$tenant->slug}")
                ->startUrl("/?tenant={$tenant->slug}")
                ->themeColor($tenant->color)
                ->icon($tenant->icon_url, '512x512');
        }
    }
}
```

Register it in config (`'manifest' => ['resolvers' => [TenantManifest::class]]`, resolved from the
container) or with `Pwa::manifest()->resolveUsing(TenantManifest::class)`. For a closure, pass the
parameters as the second argument: `resolveUsing($resolver, fn (Request $page) => ['team' => …])`.

- Parameters are public and cached: no secrets and no user IDs.
- Each tenant needs its own `id`, otherwise the browser treats them as the same app.
- `<x-pwa::head/>` takes `theme-color` and the iOS app name from the resolved manifest, so the
  resolver also runs when every page renders. Keep it fast (cache, no heavy queries).
- An error in a resolver shows up in `pwa:doctor`.

## Icons

`pwa:icons` creates these files, and the manifest then lists them:

| File | Used for |
| --- | --- |
| `icon-192.png`, `icon-512.png` | `purpose: any` – required for installation |
| `maskable-512.png` | `purpose: maskable` – Android adaptive icon |
| `monochrome-512.png` | `purpose: monochrome` – the shape only, tinted by the system (Android themed icons, notification badges) |
| `icon.svg` | `sizes: any` (only from an SVG source) |
| `apple-touch-icon.png` | iPhone and iPad (through `<x-pwa::head/>`, not the manifest) |
| `favicon-32.png` | for your own `<link rel="icon">` |
| `splash/apple-splash-*.png` | iOS startup images (with `--splash`, through `<x-pwa::head/>`) |

## Screenshots

Chrome on Android and desktop shows a richer install dialog with screenshots. Add at least one
`wide` (desktop) and one `narrow` (phone) screenshot:

```php
// config/pwa.php
'screenshots' => [
    ['src' => '/pwa/screenshots/desktop.png', 'sizes' => '1280x800', 'type' => 'image/png', 'form_factor' => 'wide', 'label' => 'Dashboard'],
    ['src' => '/pwa/screenshots/phone.png', 'sizes' => '750x1334', 'type' => 'image/png', 'form_factor' => 'narrow', 'label' => 'Orders'],
],
```

Chrome ignores a screenshot whose `sizes` or `type` don't match the file. `pwa:doctor` opens every
local screenshot and compares them. `Pwa::manifest()->screenshot()` adds more in code.

## Checks

`pwa:doctor`, `ManifestValidator` and the `assertValidManifest()` test helper check the OS
integration members, which browsers otherwise drop without a word:

| Member | Checked |
| --- | --- |
| `shortcuts` | a `name`, a `url` inside the scope, at most 10 entries |
| `share_target` | `action` inside the scope, `GET` or `POST`, files only with `POST` + `multipart/form-data`, every file field has a `name` and `accept` |
| `file_handlers` | `action` inside the scope, `accept` maps MIME types to extensions with a dot |
| `protocol_handlers` | `web+name` or a safelisted scheme (`mailto`, `tel` …), a `url` inside the scope containing `%s` |
| `launch_handler` | `client_mode` is `navigate-existing`, `navigate-new`, `focus-existing` or `auto` |

## Localization

- `lang` and `dir` follow the manifest locale.
- `name`, `short_name` and `description` come from the `pwa::manifest.*` translations, otherwise
  from config:

```php
// lang/vendor/pwa/en/manifest.php
return ['name' => 'Orders', 'short_name' => 'Orders', 'description' => 'Order management'];
```

- With `locales => ['en', 'de']`, the manifest gains `name_localized`, `short_name_localized` and
  `description_localized` (Chromium 147+ on desktop).
- With a non-empty `locales`, `<x-pwa::head/>` appends `?lang={page locale}` to the link and the
  manifest is built in that locale (the manifest route has no session, so otherwise everyone would
  get the default locale). Only locales listed in `locales` are allowed (include the default one
  too); any other value is ignored. `cs_CZ` and `cs-CZ` are the same; the manifest `lang` always
  uses a hyphen.

## Manual check

```bash
curl -s https://app.test/manifest.webmanifest | jq
```

In Chrome DevTools, Application → Manifest shows installability errors.
