---
title: Service worker
description: Jak vzniká /sw.js, životní cyklus, pravidla a strategie cache, verze, aktualizace, sw.import a sw.extend.
---

# Service worker

## Jak vzniká

`/sw.js` je routa balíčku, ne soubor v `public/`. Obsah sestaví `ServiceWorkerBuilder`:

```js
/* nyoncode/laravel-pwa service worker — generováno, needitovat. */
self.__PWA = { "precache": [...], "version": "1a2b3c4d5e6f", ... };
importScripts("/push-sw.js");          // jen se sw.import
/* resources/sw/runtime.js */
importScripts("/pwa-extend.js");       // jen se sw.extend
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

`version` je hash z: runtime konfigurace (včetně precache seznamu a pravidel cache), seznamů
`sw.import` a `sw.extend`, obsahu těchto souborů a souboru `runtime.js`. Precache obsahuje hashované názvy z Vite, takže:

- nový build assetů = nová verze SW = aktualizace u uživatelů,
- beze změny assetů a configu = stejný soubor = prohlížeč nic nestahuje.

`pwa:doctor` hlásí chybu, když sestavený SW nesedí s aktuálním configem nebo buildem.

## Co se předcachuje

| `sw.precache_vite` | Soubory z Vite manifestu |
| --- | --- |
| `imports` (výchozí) | vstupní body, všechny chunky, které staticky importují, jejich CSS a assety (fonty, obrázky) |
| `entries` | jen vstupní body a jejich CSS |
| `all` | všechny chunky včetně dynamických importů (`import()`) |
| `false` | nic z Vite |

Vždy se předcachuje: offline stránka, klient v prohlížeči (`/pwa/client.js?v=…`, offline se servíruje,
i když `/pwa/*` se jinak nikdy necachuje) a `offline.precache`. S `imports` dostane stránka, která
načítá JavaScript offline, i sdílený vendor chunk, který potřebuje; s `entries` se ten chunk uloží až
po otevření stránky online.

```php
// config/pwa.php
'sw' => [
    'precache_vite' => 'imports',
    'precache_exclude' => ['assets/*.mp4', 'assets/hero-*.jpg'], // relativně k adresáři buildu
    'precache_warn_kb' => 5120,
],
```

Celý precache stáhne každý návštěvník při instalaci workeru. `pwa:build` vypíše jeho velikost a nad
`precache_warn_kb` varuje; hlásí ji i `pwa:doctor`. Vynechané soubory se i tak uloží cache-first při
prvním použití (`/build/*`).

## Životní cyklus

1. **install**: stáhne [precache](#co-se-předcachuje) (offline stránka, klient, `offline.precache`, soubory z Vite manifestu).
   Jednotlivě, takže chybějící soubor instalaci nezablokuje (chyba jde do telemetrie).
2. **waiting**: nová verze čeká, dokud běží stará. Klient ukáže lištu „Je k dispozici nová verze“.
3. **activate** (po potvrzení `SKIP_WAITING`): smaže staré `pwa-static-*`, cache pravidel, která už
   neexistují, cache verzí 0.1.x (`pwa-api-*`, `pwa-images*`), při změně verze i uložené stránky
   (odkazují na staré assety), zapne Navigation Preload, převezme klienty.
4. Klient po `controllerchange` stránku znovu načte, jen pokud aktualizaci potvrdil uživatel.

## Pořadí zpracování

| Požadavek | Zpracování | Cache |
| --- | --- | --- |
| `/sw.js`; navigace na cesty z `cache.never_cache` | SW nezasahuje | – |
| fetch/XHR na cesty z `cache.never_cache` | rovnou na síť, nic neukládá ani neřadí do fronty; jen přečte hlavičky identity | – |
| POST/PUT/PATCH/DELETE (`fetch`/XHR) | síť; při výpadku fronta a `202` | IndexedDB `pwa` / `queue` |
| navigace (HTML) | síť (s Navigation Preload); po `cache.navigation_timeout` cache, pokud existuje; při chybě cache, pak `/offline` | `pwa-pages-{user}` |
| `/build/*` a precache | cache-first | `pwa-static-{version}` |
| trasy ze `sw.extend` | vlastní handler aplikace | podle handleru |
| ostatní GET na vlastní doméně | první odpovídající [pravidlo](#pravidla-cache) | `pwa-rule-{název}-{user}` nebo `pwa-shared-{název}` |
| cizí domény bez trasy ze `sw.extend` | SW nezasahuje | – |

Co se nikdy neukládá: odpovědi jiné než 200, `Cache-Control: no-store`, odpovědi 503, HTML bez
`X-PWA-Cache: allow`.

## Pravidla cache

Pravidla určují, jak SW odpovídá na GET požadavky, které nejsou navigace (API, JSON, obrázky,
soubory). Platí první pravidlo, které sedí. Pořadí: `cache.rules` z configu, pravidla z kódu,
zkratka `cache.api`, nakonec výchozí pravidlo pro obrázky a fonty.

```php
// config/pwa.php
'cache' => [
    'rules' => [
        ['match' => '/api/catalog*', 'strategy' => 'stale-while-revalidate', 'cache' => 'catalog', 'max_entries' => 200, 'max_age' => 86400],
        ['match' => '/avatars/*', 'destination' => 'image', 'strategy' => 'cache-first', 'max_age' => 604800],
        ['match' => '/api/stock', 'strategy' => 'network-first', 'timeout' => 2],
        ['match' => '/api/live*', 'strategy' => 'network-only'],
    ],
],
```

Totéž v kódu, např. v `AppServiceProvider::boot()`:

```php
use NyonCode\LaravelPwa\Facades\Pwa;

Pwa::serviceWorker()->cache('/api/catalog*')->staleWhileRevalidate()->name('catalog')->maxEntries(200)->maxAge(days: 1);
Pwa::serviceWorker()->cache('/api/stock')->networkFirst(timeout: 2);
Pwa::serviceWorker()->cache(null)->destination('font')->cacheFirst()->maxAge(days: 365);
```

| Klíč | Metoda | Význam |
| --- | --- | --- |
| `match` | `cache('/cesta*')` | cesta s `*`, porovnává se bez query stringu (každá URL se ale ukládá zvlášť) |
| `destination` | `->destination('image', 'font')` | omezí pravidlo na `Request.destination` |
| `strategy` | `->networkFirst()` … | viz tabulka strategií |
| `cache` | `->name('catalog')` | název cache (`a-z`, `0-9`, `-`); bez něj se odvodí z pravidla |
| `max_entries` | `->maxEntries(200)` | po překročení se mažou nejstarší záznamy |
| `max_age` | `->maxAge(days: 1)` | starší záznam se nepoužije (u network-first ani jako záloha) |
| `timeout` | `->networkFirst(timeout: 2)` | jen network-first: po kolika sekundách odpovědět z cache |
| `shared` | `->shared()` | jedna cache pro všechny uživatele, viz níže |

| Strategie | Chování |
| --- | --- |
| `network-first` | síť; z cache jen offline, při 503 (údržba) nebo po `timeout` |
| `cache-first` | cache; síť jen bez záznamu nebo po `max_age` (offline i starší záznam) |
| `stale-while-revalidate` | hned z cache, na pozadí obnoví pro příště |
| `network-only` | vždy síť, nic neukládá (vyjme cesty ze širšího pravidla) |
| `cache-only` | jen cache nebo precache; bez záznamu `504` |

### Oddělení uživatelů

Každé pravidlo má ve výchozím stavu cache **pro každého uživatele zvlášť** (`pwa-rule-{název}-{hash}`).
Při odhlášení nebo přihlášení jiného uživatele se smaže. `shared: true` vytvoří jednu společnou
cache (`pwa-shared-{název}`), která odhlášení přežije. SW do ní uloží jen odpovědi, které nejsou
`Cache-Control: private` a nepatří přihlášenému uživateli (`X-PWA-User` chybí nebo je `guest`).
Hodí se pro veřejný katalog nebo číselníky, nikdy ne pro osobní data.

Výchozí pravidlo pro obrázky a fonty (`cache.images`) je stale-while-revalidate s limitem
`max_entries` a stářím `max_age`; vypnete ho `'images' => ['enabled' => false]`.

`pwa:build` a `pwa:doctor` pravidla kontrolují: neznámý klíč nebo strategie, neplatný název,
`timeout` jinde než u network-first, stejný název pro sdílenou a osobní cache a sdílené pravidlo
přes cesty z `never_cache`.

## Hlavičky, které SW čte

| Hlavička | Posílá | Účinek |
| --- | --- | --- |
| `X-PWA-Cache: allow` | middleware `pwa.cache` | HTML smí do cache |
| `X-PWA-User: {hash}` | `PwaWebMiddleware` (skupina `web`) | změna uživatele smaže cache předchozího; SW ji čte z každé odpovědi vlastní domény (navigace, mutace, pravidla, `never_cache`), takže zachytí i XHR přihlášení v Inertii a Livewire |
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

## sw.extend

Skripty ze `sw.extend` se načtou **po** runtime a dostanou `self.PwaWorker`: vlastní trasy se
stejnými strategiemi a stejným oddělením uživatelů jako pravidla.

```php
'sw' => ['extend' => ['/pwa-extend.js']],
```

```js
// public/pwa-extend.js
const { route, strategies, cacheName } = self.PwaWorker;

// Reporty: cache-first, osobní cache (smaže se při odhlášení)
route('/reports/*', async (event) =>
  strategies.cacheFirst(event, { cacheName: await cacheName('reports'), maxEntries: 20, maxAge: 3600 }));

// Fonty z CDN (cizí doména jen přes RegExp nebo funkci)
route(/^https:\/\/fonts\.gstatic\.com\//, (event) =>
  strategies.cacheFirst(event, { cacheName: 'pwa-shared-fonts', shared: true }));

// Vlastní odpověď
route(({ url }) => url.pathname === '/api/health', () => new Response('{"ok":true}'));
```

| Člen | Popis |
| --- | --- |
| `route(match, handler)` | `match`: cesta s `*` (jen vlastní doména), `RegExp` na celou URL, nebo funkce `({url, request, event}) => bool`; `handler(event, context)` vrací `Response` |
| `strategies` | `networkFirst`, `cacheFirst`, `staleWhileRevalidate`, `networkOnly`, `cacheOnly`; volba `{cacheName, maxEntries, maxAge, timeout, shared}` |
| `cacheName(name, {shared})` | `pwa-rule-{name}-{user}` (smaže se při odhlášení), se `shared` `pwa-shared-{name}` |
| `currentUser()` | hash aktuálního uživatele nebo `guest` |
| `broadcast(message)` | pošle zprávu všem oknům (`{source: 'pwa', ...}`) |
| `version`, `config` | verze SW a `self.__PWA` |

Trasy ze `sw.extend` se neuplatní na navigace, `never_cache` a mutace: HTML se tak nikdy
neuloží bez souhlasu serveru.

## Ladění

- Chrome DevTools → Application → Service workers: stav, Update on reload, Bypass for network.
- Application → Cache storage: `pwa-static-*`, `pwa-pages-*`, `pwa-rule-*`, `pwa-shared-*`, `pwa-meta`.
- Application → IndexedDB → `pwa` → `queue`: fronta offline akcí.
- `await Pwa.version()` v konzoli vrátí verzi aktivního SW.
- Chyby SW jsou v logu s prefixem `[pwa]` ([Bezpečnost](security.md#telemetrie)).
