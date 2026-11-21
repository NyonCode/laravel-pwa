# Laravel PWA

Progressive Web App pro Laravel 12 a 13: manifest, service worker, offline režim,
obrazovka údržby, trvalé přihlášení v nainstalované aplikaci a nouzové vypnutí.
Postaveno na [`nyoncode/laravel-package-toolkit`](https://github.com/NyonCode/laravel-package-toolkit).

- **Jedna aplikace na jedné adrese.** Edge a Chrome na desktopu dostanou `minimal-ui`
  (tlačítka Zpět a Obnovit), telefony, tablety a Safari `standalone`, bez detekce zařízení.
- **Offline.** Precache z Vite buildu, stránky v cache jen se souhlasem serveru,
  oddělené pro každého uživatele, fronta formulářů a API volání, když není síť.
- **Údržba.** `php artisan pwa:down` ukáže vlastní obrazovku, SW odliší 503 od výpadku
  sítě a nic z cache nesmaže.
- **Trvalé přihlášení.** V nainstalované aplikaci vždy remember-me, klouzavá session,
  automatické obnovení CSRF (žádné 419).
- **Nouzové vypnutí.** `php artisan pwa:disable` odstraní service worker ze všech zařízení.
- **Push notifikace nejsou součástí balíčku.** Push systém dostane místo ve service
  workeru (`sw.import`), viz [docs/push.md](docs/push.md).

## Instalace

```bash
composer require nyoncode/laravel-pwa
php artisan pwa:install
```

Do `<head>` layoutu:

```blade
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<x-pwa::head/>
```

Volitelné komponenty:

```blade
<x-pwa::nav/>                                   {{-- Zpět / Obnovit tam, kde je desktop nenabízí --}}
<x-pwa::install class="btn">Nainstalovat</x-pwa::install>
```

Ikony z jednoho obrázku (PNG s `ext-gd`, SVG s `ext-imagick`):

```bash
php artisan pwa:icons resources/pwa/icon.png
```

Routy:

```php
Route::post('/login', LoginController::class)->middleware('pwa.remember'); // remember-me v aplikaci
Route::get('/dashboard', DashboardController::class)->middleware('pwa.cache'); // dostupné offline
```

Výjimky z údržby v `bootstrap/app.php` (manifest a SW zůstanou dostupné):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->preventRequestsDuringMaintenance(except: ['manifest.webmanifest', 'sw.js', 'pwa/*']);
})
```

Deploy:

```bash
npm run build
php artisan pwa:build   # nebo php artisan optimize
```

Kontrola nastavení:

```bash
php artisan pwa:doctor        # přehled
php artisan pwa:doctor --ci   # chybový kód při chybě, do deploy skriptu
```

## Příkazy

| Příkaz | Co dělá |
| --- | --- |
| `pwa:install` | publikuje config a vypíše další kroky |
| `pwa:build` / `pwa:clear` | sestaví / smaže service worker (napojeno na `optimize`) |
| `pwa:icons {source}` | ikony any, maskable, apple-touch-icon, favicon |
| `pwa:down` | `artisan down` s obrazovkou PWA, `--message`, `--until`, `--retry`, `--secret` |
| `pwa:disable` / `pwa:enable` | nouzové vypnutí service workeru na všech zařízeních |
| `pwa:doctor` | kontrola HTTPS, ikon, buildu, výjimek z údržby, session |

## Dokumentace

Podrobná dokumentace je v [`docs/`](docs/index.md):

| Začínáme | Funkce | Integrace | Provoz |
| --- | --- | --- | --- |
| [Přehled](docs/index.md) | [Režim zobrazení](docs/display-modes.md) | [JavaScript klient](docs/client.md) | [Příkazy](docs/commands.md) |
| [Instalace](docs/installation.md) | [Manifest](docs/manifest.md) | [Push notifikace](docs/push.md) | [Nasazení](docs/deployment.md) |
| [Konfigurace](docs/configuration.md) | [Blade komponenty](docs/components.md) | [Livewire a Inertia](docs/adapters.md) | [Bezpečnost](docs/security.md) |
| | [Service worker](docs/service-worker.md) | [AI agenti](docs/ai.md) | [Podpora platforem](docs/platforms.md) |
| | [Offline](docs/offline.md) | | [Řešení problémů](docs/troubleshooting.md) |
| | [Trvalé přihlášení](docs/session.md) | | [Testování](docs/testing.md) |
| | [Údržba](docs/maintenance.md) | | |
| | [Nouzové vypnutí](docs/kill-switch.md) | | |

## AI agenti a MCP

Balíček nese dokumentaci pro coding agenty uvnitř `vendor/`, takže agent čte verzi z vašeho
`composer.lock`:

```bash
vendor/bin/pwa-ai install
```

Přidá blok do `AGENTS.md` (odkaz na [`ai/AGENTS.md`](ai/AGENTS.md)), Claude Code skill
`laravel-pwa` a MCP server `laravel-pwa` do `.mcp.json` (Node 18+, bez závislostí). Nástroje
serveru: `search_docs`, `list_docs`, `get_doc`, `list_api`, `describe_api`, `describe_config`,
`list_commands`, `describe_client`. Podrobnosti v [docs/ai.md](docs/ai.md).

## Licence

MIT, viz [LICENSE.md](LICENSE.md).
