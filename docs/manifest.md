---
title: Manifest
description: Jak vzniká /manifest.webmanifest, fluent API Pwa::manifest(), ikony, lokalizace a integrace do systému.
---

# Manifest

`GET /manifest.webmanifest` sestaví `ManifestBuilder` z configu a z volání v kódu aplikace.
Odpověď má `Content-Type: application/manifest+json`, `ETag` (304 při shodě) a cachuje se hodinu.

## Pořadí skládání

1. Config (`config/pwa.php`).
2. Strategie zobrazení (`display`, `display_override`).
3. Ikony, které existují v `public/{icons.path}`.
4. Lokalizované členy (`locales`).
5. Fluent volání `Pwa::manifest()->…` (vyhrávají).
6. Prázdné hodnoty (`null`, `''`, `[]`) se vynechají.

## Fluent API

Volejte v `AppServiceProvider::boot()`:

```php
use NyonCode\LaravelPwa\Facades\Pwa;

Pwa::manifest()
    ->shortcut('Nová objednávka', '/orders/create', icon: '/pwa/icons/new.png', description: 'Rychlé vytvoření')
    ->shareTarget('/share', 'POST', ['image/*', 'application/pdf'], filesField: 'files')
    ->fileHandler('/open', ['text/csv' => ['.csv']], name: 'CSV')
    ->protocolHandler('web+objednavka', '/orders/%s')
    ->windowControlsOverlay()
    ->tabbed(['home_tab' => ['scope_patterns' => [['pathname' => '/']]]])
    ->launchHandler(['navigate-existing', 'auto'])
    ->set('categories', ['business', 'productivity']);
```

| Metoda | Výsledek v manifestu | Kde funguje |
| --- | --- | --- |
| `shortcut(name, url, icon?, description?)` | přidá do `shortcuts` | Android, Chromium desktop |
| `shareTarget(action, method, accept, filesField)` | `share_target` (GET, POST, s soubory `multipart/form-data`) | Android, ChromeOS, Windows |
| `fileHandler(action, accept, name?)` | přidá do `file_handlers` | Chromium desktop |
| `protocolHandler(protocol, url)` | přidá do `protocol_handlers` | Chromium desktop |
| `windowControlsOverlay()` | `window-controls-overlay` na začátek `display_override` | Chromium desktop |
| `tabbed(tabStrip)` | `tabbed` do `display_override`, volitelně `tab_strip` | Chromium desktop |
| `launchHandler(clientMode)` | `launch_handler.client_mode` | Chromium desktop |
| `set(key, value)` | libovolný člen | – |
| `toArray(request?)`, `toJson(request?)` | výsledek | – |
| `icons()` | seznam existujících ikon | – |
| `flush()` | zahodí fluent nastavení (testy) | – |

### Share target

Routa, na kterou share target míří, musí přijmout POST bez CSRF (sdílení přichází ze systému):

```php
Route::post('/share', ShareController::class)
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
```

Pole: `title`, `text`, `url` a soubory v `files`.

### File handlers

Soubor otevřený z OS přijde přes `launchQueue`:

```js
if ('launchQueue' in window) {
  launchQueue.setConsumer(async ({ files }) => {
    for (const handle of files) importCsv(await handle.getFile());
  });
}
```

## Ikony

`pwa:icons` vytvoří a manifest pak uvede:

| Soubor | Použití |
| --- | --- |
| `icon-192.png`, `icon-512.png` | `purpose: any` – povinné pro instalaci |
| `maskable-512.png` | `purpose: maskable` – adaptivní ikona Androidu |
| `icon.svg` | `sizes: any` (jen ze SVG zdroje) |
| `apple-touch-icon.png` | iPhone a iPad (přes `<x-pwa::head/>`, ne manifest) |
| `favicon-32.png` | pro vlastní `<link rel="icon">` |

## Lokalizace

- `lang` a `dir` podle `app()->getLocale()`.
- `name`, `short_name`, `description` z překladů `pwa::manifest.*`, jinak z configu:

```php
// lang/vendor/pwa/en/manifest.php
return ['name' => 'Orders', 'short_name' => 'Orders', 'description' => 'Order management'];
```

- S `locales => ['en', 'de']` přibudou `name_localized`, `short_name_localized`,
  `description_localized` (Chromium 147+ na desktopu).

## Ruční kontrola

```bash
curl -s https://app.test/manifest.webmanifest | jq
```

V Chrome DevTools: Application → Manifest ukáže chyby instalovatelnosti.
