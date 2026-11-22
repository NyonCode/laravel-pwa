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

## Service worker se při lokálním vývoji neregistruje

`pwa.enabled` je v prostředí `local` ve výchozím stavu vypnuté, aby worker v cache nikdy neschoval vaše
změny během vývoje. Pro vyzkoušení PWA lokálně nastavte v `.env` `PWA_ENABLED=true`; upozorní na to
i `pwa:doctor`. `localhost` a `127.0.0.1` jsou bezpečné originy, jiné lokální hosty potřebují HTTPS.

## Přepínač oznámení nic nedělá nebo hlásí, že oznámení nejsou dostupná

- Anonymní okno: Chrome tam push nepodporuje a stránka to nemůže předem zjistit. Přihlášení k odběru
  selže, přepínač přejde do stavu `unavailable` a ukáže nápovědu `push_unavailable`.
- Prohlížeč bez push služby (některá sestavení Chromia, Brave bez „Google services for push messaging“):
  stejný stav `unavailable`.
- Zablokovaná oznámení: stav `denied`; uživatel je musí povolit v nastavení webu.
- iPhone, iPad: push funguje jen v aplikaci přidané na plochu (`install-required`).

Pro zalogování nebo vysvětlení poslouchejte `pwa:push` (`{ state, error }`).

## Uživatel se přihlásil k odběru, ale oznámení nepřijde

- Model uživatele nemá trait `HasPushSubscriptions` (ani `routeNotificationForWebPush()`): odběr se uloží,
  ale `WebPush::send($user)` žádný nenajde. `pwa:doctor` na to upozorní.
- `WebPush::queue()` a notifikace ve frontě potřebují běžící queue worker (`php artisan queue:work`).
- Host push služby není v `pwa.push.endpoints`: `PushFailed` s „Endpoint host is not in
  pwa.push.endpoints“.
- Změnily se klíče VAPID: staré odběry neplatí, dokud se prohlížeče znovu nepřihlásí (klient je obnoví při další návštěvě).
- Oznámení skrývá režim soustředění systému nebo nastavení prohlížeče: v DevTools ověřte
  `registration.getNotifications()`.

## Livewire starter kit: stránky nastavení vrací 500

„No hint path defined for [layouts]“ na `/settings/profile` ve starter kitu Livewire postaveném na Voltu
s Livewire 4. Chyba je v kitu, ne v balíčku: jeho Volt stránky nemají `#[Layout]` a Livewire 4 použije
`layouts::app`, který kit nemá. Publikujte konfiguraci Livewire (`php artisan livewire:config`) a nastavte
layout kitu:

```php
// config/livewire.php
'component_layout' => 'components.layouts.app',
```

## Ladění service workeru

```js
await Pwa.version()                       // verze aktivního SW
(await navigator.serviceWorker.getRegistrations()).length
await caches.keys()
```

Chrome: `chrome://serviceworker-internals`, `chrome://web-app-internals`.
