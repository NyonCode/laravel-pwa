---
title: Údržba
description: Obrazovka údržby, pwa:down, chování service workeru při 503, výjimky a CSP.
---

# Údržba

Při `php artisan down` dostane uživatel obrazovku údržby (ne offline stránku ani chybu prohlížeče)
a nainstalovaná aplikace nepřijde o cache, přihlášení ani push odběr.

## Zapnutí

```bash
php artisan pwa:down --message="Nasazujeme novou verzi" --until="15:30"
php artisan up
```

| Volba | Popis |
| --- | --- |
| `--message` | text na obrazovce (jinak `maintenance.message` nebo přeložený výchozí) |
| `--until` | očekávaný konec: `15:30`, `2026-11-21 08:00`, cokoli pro `Carbon::parse` |
| `--retry` | sekundy pro `Retry-After`; bez něj z `--until` (omezeno 15–300 s) nebo `maintenance.retry` |
| `--refresh` | hlavička `Refresh` |
| `--secret`, `--with-secret` | obejití údržby jako u `artisan down` |

`pwa:down` je wrapper nad `php artisan down --render="pwa::maintenance"`. Funguje i přímo:

```bash
php artisan down --render="pwa::maintenance" --retry=120
```

## Obrazovka

Předrenderuje se při `down`, takže nemá request, session ani auth. Ukazuje zprávu, odhad konce,
odpočet do další kontroly a ukazatel průběhu. Každých `Retry-After` sekund (15–300) pošle
`HEAD` na aktuální URL; jakmile odpověď není 503, stránku znovu načte. Kontroluje i po návratu do
aplikace a po obnovení připojení.

Úpravy: publikujte views a upravte `maintenance.blade.php`. Data z PHP předávejte jen přes `data-*`
atributy elementu `#pwa-maintenance`, ne do `<script>`: jinak se změní CSP hash ([Bezpečnost](security.md)).

## Service worker při 503

| Situace | Chování |
| --- | --- |
| navigace vrátí 503 | SW odpověď zobrazí, neukáže offline stránku, nic neuloží |
| uložené stránky | zůstanou; 503 je nepřepíše |
| GET z `cache.api` | vrátí se uložená odpověď, jinak 503 |
| fronta offline akcí | při 503 čeká dál |
| otevřená okna | dostanou zprávu `maintenance` (lišta, `pwa:maintenance`) |
| kontrola aktualizace `/sw.js` vrátí 503 | prohlížeč si nechá stávající SW |
| push | doručení nejde přes server; klepnutí otevře obrazovku údržby |

## Výjimky

```php
$middleware->preventRequestsDuringMaintenance(except: ['manifest.webmanifest', 'sw.js', 'pwa/*']);
```

Laravel výjimky respektuje i u předrenderované šablony. S nimi zůstanou dostupné manifest,
service worker, keep-alive a telemetrie. `pwa:doctor` chybějící výjimky hlásí jako varování.

## Obejití údržby

`--secret` funguje jako v Laravelu (`https://app.test/{secret}` nastaví cookie). Na iPhonu se ale
odkaz otevře v Safari, ne v aplikaci na ploše, která má vlastní cookies. Bypass testujte na
Androidu nebo desktopu, kde aplikace sdílí cookies s prohlížečem.

## Livewire a Inertia

Akce během údržby dostanou 503; adaptéry místo chybového okna stránku znovu načtou, takže se ukáže
obrazovka údržby ([Livewire a Inertia](adapters.md)).
