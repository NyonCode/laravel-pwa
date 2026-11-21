---
title: Manifest
description: Jak vzniká /manifest.webmanifest, fluent API Pwa::manifest(), resolvery pro tenanty a jazyky, ikony, lokalizace a integrace do systému.
---

# Manifest

`GET /manifest.webmanifest` sestaví `ManifestBuilder` z configu a z volání v kódu aplikace.
Odpověď má `Content-Type: application/manifest+json`, `ETag` (304 při shodě), `Content-Language`
a cachuje se hodinu (`private`, když to resolver nebo adaptivní strategie zobrazení vyžaduje).

## Pořadí skládání

1. Config (`config/pwa.php`).
2. Strategie zobrazení (`display`, `display_override`).
3. Ikony, které existují v `public/{icons.path}`.
4. Lokalizované členy (`locales`).
5. Fluent volání `Pwa::manifest()->…` z bootu.
6. [Resolvery](#resolvery-tenanti-a-branding) pro konkrétní požadavek (vyhrávají).
7. Prázdné hodnoty (`null`, `''`, `[]`) se vynechají.

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
    ->screenshot('/pwa/screenshots/desktop.png', '1280x800', 'wide', 'Přehled')
    ->category('business', 'productivity');
```

| Metoda | Výsledek v manifestu | Kde funguje |
| --- | --- | --- |
| `shortcut(name, url, icon?, description?, iconSizes = '96x96')` | přidá do `shortcuts` | Android, Chromium desktop |
| `shareTarget(action, method, accept, filesField)` | `share_target` (GET, POST, s soubory `multipart/form-data`) | Android, ChromeOS, Windows |
| `fileHandler(action, accept, name?)` | přidá do `file_handlers` | Chromium desktop |
| `protocolHandler(protocol, url)` | přidá do `protocol_handlers` | Chromium desktop |
| `windowControlsOverlay()` | `window-controls-overlay` na začátek `display_override` | Chromium desktop |
| `tabbed(tabStrip)` | `tabbed` do `display_override`, volitelně `tab_strip` | Chromium desktop |
| `launchHandler(clientMode)` | `launch_handler.client_mode` | Chromium desktop |
| `screenshot(src, sizes, formFactor?, label?, type = 'image/png')` | přidá do `screenshots` | instalační dialog Chromia |
| `category(...categories)` | přidá do `categories` | výpisy v obchodech |
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

Pole: `title`, `text`, `url` a soubory v `files`. Sdílení přijde jako POST navigace, kterou worker nechá
síti (do [fronty offline akcí](offline.md) jdou jen mutace přes fetch/XHR), takže sdílení potřebuje
připojení. Po POST přesměrujte, aby opětovné načtení nesdílelo podruhé:

```php
class ShareController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $note = $request->user()->notes()->create($request->only('title', 'text', 'url'));

        foreach ($request->file('files', []) as $file) {
            $note->addAttachment($file);
        }

        return to_route('notes.show', $note); // 303 po POST
    }
}
```

`pwa:doctor` a `assertValidManifest()` varují, když je `action` mimo scope nebo když se sdílejí soubory
bez `POST` a `multipart/form-data`.

### File handlers

Soubor otevřený z OS přijde přes `launchQueue`:

```js
if ('launchQueue' in window) {
  launchQueue.setConsumer(async ({ files }) => {
    for (const handle of files) importCsv(await handle.getFile());
  });
}
```

## Resolvery (tenanti a branding)

Resolver upraví manifest pro konkrétní požadavek: jiný název, barvy, ikony nebo `start_url` pro
každého tenanta či značku. Běží nad kopií builderu, takže nic nepřetéká mezi požadavky (Octane).

```php
// AppServiceProvider::boot()
Pwa::manifest()->resolveUsing(function (Request $request, ManifestBuilder $manifest): void {
    if ($request->getHost() === 'shop.example.com') {
        $manifest->name('Shop')->themeColor('#e11d48')->icon('/brand/shop.svg', 'any')->vary('Host');
    }
});
```

Typované metody: `name()`, `shortName()`, `description()`, `id()`, `startUrl()`, `scope()`,
`themeColor()`, `backgroundColor()`, `icon($src, $sizes, $type = null, $purpose = 'any')`,
`locale()`. Jakmile resolver přidá ikonu, ikony z `pwa:icons` se nevypíšou.
`private()` pošle `Cache-Control: private`, `vary('Host')` hlavičku `Vary`.

### Parametry odkazu na manifest

Route manifestu běží **bez session a cookies** (aby šla cachovat), takže resolver na ní nevidí
přihlášeného uživatele ani tenanta ze session. Co potřebuje, si předá v URL: třída implementuje
i `ManifestParameters` a `<x-pwa::head/>` parametry přidá do `<link rel="manifest">`.

```php
use NyonCode\LaravelPwa\Contracts\ManifestParameters;
use NyonCode\LaravelPwa\Contracts\ManifestResolver;

final class TenantManifest implements ManifestParameters, ManifestResolver
{
    // Na stránce (se session): které parametry přidat do odkazu.
    public function parameters(Request $page): array
    {
        return ['tenant' => tenant()->slug];
    }

    // Na /manifest.webmanifest?tenant=acme: parametr je vstup od uživatele, ověřte ho.
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

Registrace v configu (`'manifest' => ['resolvers' => [TenantManifest::class]]`, třída z kontejneru)
nebo `Pwa::manifest()->resolveUsing(TenantManifest::class)`. U closure předejte parametry druhým
argumentem: `resolveUsing($resolver, fn (Request $page) => ['team' => …])`.

- Parametry jsou veřejné a cachované: žádná tajemství ani ID uživatele.
- Každý tenant potřebuje vlastní `id`, jinak ho prohlížeč považuje za stejnou aplikaci.
- `<x-pwa::head/>` bere `theme-color` a název pro iOS z vyřešeného manifestu, resolver tedy
  běží i při vykreslení každé stránky. Držte ho rychlý (cache, žádné těžké dotazy).
- Chyba v resolveru se ukáže v `pwa:doctor`.

## Ikony

`pwa:icons` vytvoří a manifest pak uvede:

| Soubor | Použití |
| --- | --- |
| `icon-192.png`, `icon-512.png` | `purpose: any` – povinné pro instalaci |
| `maskable-512.png` | `purpose: maskable` – adaptivní ikona Androidu |
| `monochrome-512.png` | `purpose: monochrome` – jen tvar, barvu dodá systém (tematické ikony Androidu, odznaky notifikací) |
| `icon.svg` | `sizes: any` (jen ze SVG zdroje) |
| `apple-touch-icon.png` | iPhone a iPad (přes `<x-pwa::head/>`, ne manifest) |
| `favicon-32.png` | pro vlastní `<link rel="icon">` |
| `splash/apple-splash-*.png` | úvodní obrazovky iOS (s `--splash`, přes `<x-pwa::head/>`) |

## Screenshoty

Chrome na Androidu a desktopu ukáže se screenshoty bohatší instalační dialog. Přidejte aspoň jeden
`wide` (desktop) a jeden `narrow` (telefon):

```php
// config/pwa.php
'screenshots' => [
    ['src' => '/pwa/screenshots/desktop.png', 'sizes' => '1280x800', 'type' => 'image/png', 'form_factor' => 'wide', 'label' => 'Přehled'],
    ['src' => '/pwa/screenshots/phone.png', 'sizes' => '750x1334', 'type' => 'image/png', 'form_factor' => 'narrow', 'label' => 'Objednávky'],
],
```

Screenshot, jehož `sizes` nebo `type` neodpovídá souboru, Chrome ignoruje. `pwa:doctor` otevře
každý lokální screenshot a porovná je. Další přidá v kódu `Pwa::manifest()->screenshot()`.

## Kontroly

`pwa:doctor`, `ManifestValidator` a testovací helper `assertValidManifest()` kontrolují členy pro
integraci do systému, které by prohlížeč jinak bez varování zahodil:

| Člen | Kontrola |
| --- | --- |
| `shortcuts` | `name`, `url` uvnitř scope, nejvýš 10 položek |
| `share_target` | `action` uvnitř scope, `GET` nebo `POST`, soubory jen s `POST` + `multipart/form-data`, každé pole souborů má `name` a `accept` |
| `file_handlers` | `action` uvnitř scope, `accept` mapuje MIME typy na přípony s tečkou |
| `protocol_handlers` | `web+název` nebo povolené schéma (`mailto`, `tel` …), `url` uvnitř scope s `%s` |
| `launch_handler` | `client_mode` je `navigate-existing`, `navigate-new`, `focus-existing` nebo `auto` |

## Lokalizace

- `lang` a `dir` podle jazyka manifestu.
- `name`, `short_name`, `description` z překladů `pwa::manifest.*`, jinak z configu:

```php
// lang/vendor/pwa/en/manifest.php
return ['name' => 'Orders', 'short_name' => 'Orders', 'description' => 'Order management'];
```

- S `locales => ['en', 'de']` přibudou `name_localized`, `short_name_localized`,
  `description_localized` (Chromium 147+ na desktopu).
- S neprázdným `locales` přidá `<x-pwa::head/>` k odkazu `?lang={jazyk stránky}` a manifest se
  sestaví v tom jazyce (route manifestu nemá session, takže by jinak dostal každý výchozí jazyk).
  Povolené jsou jen jazyky z `locales` (uveďte tam i výchozí), jiná hodnota se ignoruje.
  `cs_CZ` a `cs-CZ` jsou totéž; v manifestu je `lang` vždy s pomlčkou.

## Ruční kontrola

```bash
curl -s https://app.test/manifest.webmanifest | jq
```

V Chrome DevTools: Application → Manifest ukáže chyby instalovatelnosti.
