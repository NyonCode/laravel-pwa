---
title: Řešení problémů
description: Časté problémy s instalací, aktualizacemi, cache, přihlášením a údržbou a jak je opravit.
---

# Řešení problémů

Začněte vždy `php artisan pwa:doctor` a Chrome DevTools → Application.

## Aplikace nejde nainstalovat

| Příčina | Oprava |
| --- | --- |
| chybí ikony 192/512 | `php artisan pwa:icons …` |
| HTTP místo HTTPS | HTTPS, trusted proxies |
| `start_url` mimo `scope` | upravit config |
| service worker vypnutý (`local`) | `PWA_ENABLED=true` |
| iPhone | instalace jen ručně přes Sdílet → Přidat na plochu |
| už nainstalovaná | `Pwa.isInstalled()` |

DevTools → Application → Manifest ukáže přesný důvod.

## Uživatelé vidí starou verzi

1. `php artisan pwa:build` proběhl po `npm run build`? `pwa:doctor` hlásí „Built service worker is up to date“.
2. CDN necachuje `/sw.js`?
3. Nová verze čeká, dokud uživatel neklikne „Načíst“ nebo nezavře všechna okna aplikace.
4. V nouzi: `php artisan pwa:disable`, nasadit opravu, `php artisan pwa:enable`.

## Stránka není dostupná offline

- Má routa middleware `pwa.cache`? (`curl -I` musí ukázat `X-PWA-Cache: allow`)
- Odpovídá 200 bez `Cache-Control: no-store`?
- Navštívil ji uživatel online po instalaci SW?
- Není cesta v `cache.never_cache`?
- Po aktualizaci SW se uložené stránky mažou (odkazují na staré assety) – je potřeba je znovu navštívit.

## Offline se ukazuje chyba prohlížeče místo offline stránky

- SW ještě neovládá stránku (první návštěva) – po reloadu ano.
- Šlo o klasický POST formulář – ten SW nepřebírá.
- `offline.enabled` je `false`, nebo `/offline` selhal při precache (log `[pwa] precache`).

## Odhlašuje to po pár dnech

- `pwa.remember` na login routě? `pwa:doctor` varuje, když chybí.
- Formulář posílá pole se jménem `session.remember_field`?
- Session driver není `array`/`cookie`?
- iPhone: přihlásit se v aplikaci na ploše, ne v Safari. Viz [Trvalé přihlášení](session.md).

## 419 Page Expired

- `session.refresh_csrf` je zapnuté?
- Vlastní HTTP klient (ne `fetch`, ne `window.axios`) – zopakujte požadavek po `pwa:csrf`.
- Livewire: po 419 se token obnoví a akci stačí zopakovat.

## Akce ve frontě se neodesílají

- Fronta se odesílá po `online`, návratu do aplikace nebo Background Sync (iOS ho nemá).
- 503 (údržba) odesílání odkládá.
- Obsah fronty: DevTools → IndexedDB → `pwa` → `queue`.

## Při údržbě se ukazuje offline stránka

- Odpovídá server opravdu 503? Jiný kód (502 z proxy) SW bere jako výpadek.
- `pwa:down` použil `--render="pwa::maintenance"` (jinak výchozí Laravel 503 stránka).

## pwa:disable nefunguje

- Existuje `public/sw.js`? Smazat (`pwa:doctor` hlásí chybu).
- Více serverů a `file` cache store? `PWA_KILL_SWITCH_STORE=redis`.
- Proběhlo `cache:clear`? Příznak je v cache – zkontrolujte `php artisan about`.

## Edge na Windows neukazuje tlačítka Zpět/Obnovit

- Strategie `override`/`adaptive`: v manifestu musí být `minimal-ui` (`curl /manifest.webmanifest`).
- Aplikace nainstalovaná před změnou si manifest aktualizuje až při další kontrole (zhruba denně);
  rychleji odinstalovat a nainstalovat znovu.
- S WCO tlačítka kreslí `<x-pwa::nav/>`.

## Ladění service workeru

```js
await Pwa.version()                       // verze aktivního SW
(await navigator.serviceWorker.getRegistrations()).length
await caches.keys()
```

Chrome: `chrome://serviceworker-internals`, `chrome://web-app-internals`.
