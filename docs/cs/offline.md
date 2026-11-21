---
title: Offline
description: Offline obrazovka, stránky dostupné bez připojení (pwa.cache), předehřátí nenavštívených stránek, API cache a fronta offline akcí.
---

# Offline

## Čtyři úrovně

1. **Offline obrazovka** – vždy, když navigace selže a stránka není v cache.
2. **Stránky dostupné offline** – co povolí middleware `pwa.cache`, uloží se při návštěvě.
3. **Předehřátí** – vybrané stránky se stáhnou dopředu, bez návštěvy.
4. **Fronta akcí** – formuláře a API volání odeslané bez připojení se odešlou později.

## Offline obrazovka

`GET /offline` (`offline.route`), precachovaná při instalaci SW. Bez externích zdrojů, sama pozná,
kdy se síť vrátí, a pak načte stránku, kterou uživatel chtěl.

Obsahuje:

- stav připojení a automatické opakování (2, 4, 8 … max. 30 s), ověřené dotazem na `/pwa/ping`
  (samotné `navigator.onLine` nestačí),
- seznam stránek z cache (max. 6, nejnovější první),
- počet akcí čekajících ve frontě,
- světlý i tmavý režim, `prefers-reduced-motion`, safe-area, `aria-live`,
- texty z `pwa::messages`, barvy z `theme`.

Úpravy: `php artisan vendor:publish --tag="pwa::views"` → `resources/views/vendor/pwa/offline.blade.php`.
Zachovejte inline CSS a skript a nonce atributy.

## Stránky dostupné offline

```php
Route::middleware('pwa.cache')->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
});
```

Middleware přidá `X-PWA-Cache: allow` jen GET odpovědím 200. SW pak stránku uloží do
`pwa-pages-{user}` při každé návštěvě (vždy nejnovější verze).

Co povolit:

- přehledy a detaily, které má uživatel mít po ruce (zakázky, kontakty, dokumenty),
- stránky, jejichž zastaralost do pár hodin nevadí.

Co nepovolovat:

- platby, administraci, stránky s jednorázovými tokeny,
- stránky, které se mění každou minutu (uživatel by offline viděl starý stav bez upozornění).

Na sdíleném zařízení se cache při odhlášení maže ([Bezpečnost](security.md)).

## Předehřátí

Stránky, které uživatel nikdy neotevřel, se dají stáhnout dopředu, takže fungují offline hned po
přihlášení:

```php
// config/pwa.php
'offline' => [
    'warm' => ['urls' => ['/dashboard', '/orders'], 'max' => 20, 'interval' => 60],
],

// AppServiceProvider::boot(): stránky pro konkrétního uživatele, např. poslední zakázky
Pwa::warmUsing(fn (Request $request) => $request->user()
    ? $request->user()->orders()->latest()->limit(10)->pluck('id')->map(fn ($id) => "/orders/{$id}")
    : []);
```

Platí stejná pravidla jako pro navštívené stránky:

- uloží se jen odpovědi s `X-PWA-Cache: allow`, takže stránky dál potřebují middleware `pwa.cache`;
  ostatní se přeskočí,
- stránky jdou do `pwa-pages-{user}` uživatele, který o ně požádal, a při odhlášení se smažou,
- jen cesty na stejném originu mimo `cache.never_cache`, žádná přesměrování (přihlašovací stránka
  se nikdy neuloží).

Seznam přichází v odpovědi `/pwa/session` (`no-store`), nikdy v `sw.js`. Callback `warmUsing()`
běží při každém keep-alive požadavku, ať je levný. Worker stahuje dvě stránky najednou a posílá
`X-PWA-Warm: 1`, takže tyto požadavky poznáte (logy, rate limit).

Kdy se spouští:

| Spouštěč | Podrobnosti |
| --- | --- |
| přihlášení | po přihlašovací navigaci se worker sám zeptá `/pwa/session`; XHR přihlášení (Inertia, Livewire) přes klienta |
| keep-alive | nejvýš jednou za `offline.warm.interval` minut |
| Periodic Background Sync | nainstalované aplikace v Chromiu s uděleným oprávněním, tag `pwa-warm` |
| `Pwa.warm()` | hned, vrátí `{count, skipped, failed}` |

Se zapnutým Save-Data ani bez připojení se nic nestahuje.

```js
const { count } = await Pwa.warm()
Pwa.on('warmed', ({ count, skipped }) => console.log(`${count} stránek připraveno offline`))
```

## API cache

```php
'cache' => ['api' => ['/api/catalog*', '/api/me']],
```

GET na tyto cesty jde na síť a odpověď 200 se uloží do `pwa-rule-api-{user}`. Při výpadku sítě nebo
údržbě (503) SW vrátí uloženou odpověď. `no-store` se neukládá.

## Fronta offline akcí

Když `fetch` s metodou POST, PUT, PATCH nebo DELETE selže kvůli síti:

1. SW požadavek uloží do IndexedDB (`pwa` / `queue`): URL, metodu, vybrané hlavičky
   (`content-type`, `accept`, `x-csrf-token`, `x-xsrf-token`, `x-requested-with`, `x-inertia`,
   `x-livewire`) a tělo.
2. Vrátí `202 {"queued": true}` s hlavičkou `X-PWA-Queued: 1`.
3. Zaregistruje Background Sync (`pwa-queue`), kde je podporovaný.
4. Klientům pošle událost `queue` s počtem.

Odeslání: Background Sync, událost `online`, návrat do aplikace, start klienta nebo
`postMessage({type: 'REPLAY_QUEUE'})`. Po 419 si SW vezme nový CSRF token z `/pwa/session` a akci
zopakuje (i `_token` v `application/x-www-form-urlencoded`). Při 503 čeká dál.

Ve vlastním JS:

```js
const res = await fetch('/api/notes', { method: 'POST', body: JSON.stringify(note), headers });
if (res.headers.get('X-PWA-Queued')) toast('Uloženo, odešle se po připojení');
window.Pwa.on('replayed', ({ url, status }) => console.log('odesláno', url, status));
```

Pozor:

- **Klasický formulář** (navigace s POST) se do fronty neukládá; SW do něj nezasahuje a prohlížeč ukáže vlastní chybovou stránku.
  Fronta platí pro `fetch`/XHR (Livewire, Inertia, axios, vlastní JS).
- **Idempotence**: akce se může odeslat se zpožděním. Pro vytváření záznamů posílejte z klienta
  vlastní ID (UUID), aby opakování nevytvořilo duplicitu.
- **Pořadí**: fronta se odesílá v pořadí vložení; první chyba sítě odesílání zastaví.
- Vypnutí: `offline.queue = false`, nebo konkrétní cesty do `cache.never_cache`.
