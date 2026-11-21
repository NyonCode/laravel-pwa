---
title: Service worker
description: Jak vzniká /sw.js, životní cyklus, strategie cache, verze, aktualizace a sw.import.
---

# Service worker

## Jak vzniká

`/sw.js` je routa balíčku, ne soubor v `public/`. Obsah sestaví `ServiceWorkerBuilder`:

```js
/* nyoncode/laravel-pwa service worker — generováno, needitovat. */
self.__PWA = { "precache": [...], "version": "1a2b3c4d5e6f", ... };
importScripts("/push-sw.js");          // jen se sw.import
/* resources/sw/runtime.js */
```

| Stav | Co vrací `/sw.js` |
| --- | --- |
| `pwa:build` proběhl | soubor z `bootstrap/cache/pwa/sw.js` |
| bez buildu | sestaví se za běhu při každém požadavku |
| `pwa.enabled = false` nebo `pwa:disable` | samodestrukční SW ([Nouzové vypnutí](kill-switch.md)) |

Hlavičky: `Cache-Control: no-cache`, `Service-Worker-Allowed: {scope}`, `ETag`.
Klient registruje s `updateViaCache: 'none'`, takže prohlížeč kontroluje novou verzi při každé
navigaci.

## Verze

`version` je hash z: runtime konfigurace (včetně precache seznamu), seznamu `sw.import`, obsahu
importovaných souborů a souboru `runtime.js`. Precache obsahuje hashované názvy z Vite, takže:

- nový build assetů = nová verze SW = aktualizace u uživatelů,
- beze změny assetů a configu = stejný soubor = prohlížeč nic nestahuje.

`pwa:doctor` hlásí chybu, když sestavený SW nesedí s aktuálním configem nebo buildem.

## Životní cyklus

1. **install**: stáhne precache (offline stránka, `offline.precache`, vstupy z Vite manifestu).
   Jednotlivě, takže chybějící soubor instalaci nezablokuje (chyba jde do telemetrie).
2. **waiting**: nová verze čeká, dokud běží stará. Klient ukáže lištu „Je k dispozici nová verze“.
3. **activate** (po potvrzení `SKIP_WAITING`): smaže staré `pwa-static-*`, při změně verze i
   uložené stránky (odkazují na staré assety), zapne Navigation Preload, převezme klienty.
4. Klient po `controllerchange` stránku znovu načte, jen pokud aktualizaci potvrdil uživatel.

## Strategie

| Požadavek | Strategie | Cache |
| --- | --- | --- |
| navigace (HTML) | síť (s Navigation Preload); po `cache.navigation_timeout` cache, pokud existuje; při chybě cache, pak `/offline` | `pwa-pages-{user}` |
| `/build/*` a precache | cache-first | `pwa-static-{version}` |
| obrázky, fonty | stale-while-revalidate, limit `cache.images.max_entries` | `pwa-images-{userHash}` |
| GET z `cache.api` | síť, při chybě nebo 503 cache | `pwa-api-{user}` |
| POST/PUT/PATCH/DELETE | síť; při výpadku fronta a `202` | IndexedDB `pwa` / `queue` |
| navigace na `cache.never_cache`, cizí domény, `/sw.js` | SW nezasahuje | – |
| fetch/XHR na `cache.never_cache` | rovnou na síť, nic neukládá ani neřadí do fronty; jen přečte hlavičky identity | – |

Co se nikdy neukládá: odpovědi jiné než 200, `Cache-Control: no-store`, odpovědi 503, HTML bez
`X-PWA-Cache: allow`.

## Hlavičky, které SW čte

| Hlavička | Posílá | Účinek |
| --- | --- | --- |
| `X-PWA-Cache: allow` | middleware `pwa.cache` | HTML smí do cache |
| `X-PWA-User: {hash}` | `PwaWebMiddleware` (skupina `web`) | změna uživatele smaže cache předchozího; SW ji čte z každé odpovědi vlastní domény (navigace, mutace, API, obrázky, `never_cache`), takže zachytí i XHR přihlášení v Inertii a Livewire |
| `X-PWA-Logout: 1` | po události `Logout` | smaže cache a frontu, pošle `logout` klientům |
| `Retry-After` | údržba | předá se v události `maintenance` |

## Zprávy

Klient → SW (`navigator.serviceWorker.controller.postMessage`):

| Zpráva | Účinek |
| --- | --- |
| `{type: 'SKIP_WAITING'}` | aktivovat čekající verzi |
| `{type: 'REPLAY_QUEUE'}` | odeslat frontu |
| `{type: 'CLEAR_USER'}` | smazat cache a frontu uživatele |
| `{type: 'IDENTITY', user}` | identita z keep-alive (`/pwa/session`); jiný uživatel = smazat data předchozího |
| `{type: 'GET_VERSION'}` + `MessagePort` | vrátí `{version}` (`window.Pwa.version()`) |

SW → klient (`{source: 'pwa', type}`): `activated`, `offline`, `maintenance`, `logout`, `queued`,
`queue`, `replayed`. Klient je převádí na DOM události `pwa:*` ([Klient](client.md)).

## sw.import

```php
'sw' => ['import' => ['/push-sw.js', 'https://browser.sentry-cdn.com/…/bundle.min.js']],
```

Skripty se načtou před runtime balíčku, takže jejich listenery (`push`, `notificationclick`,
`fetch`) běží dřív. Pokud importovaný skript v `fetch` zavolá `respondWith`, runtime balíčku se
pro ten požadavek neuplatní. Lokální soubory (`/…`) ovlivňují verzi SW obsahem; cizí domény jen URL.

## Ladění

- Chrome DevTools → Application → Service workers: stav, Update on reload, Bypass for network.
- Application → Cache storage: `pwa-static-*`, `pwa-pages-*`, `pwa-api-*`, `pwa-images-*`, `pwa-meta`.
- Application → IndexedDB → `pwa` → `queue`: fronta offline akcí.
- `await Pwa.version()` v konzoli vrátí verzi aktivního SW.
- Chyby SW jsou v logu s prefixem `[pwa]` ([Bezpečnost](security.md#telemetrie)).
