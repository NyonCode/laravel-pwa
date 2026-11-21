---
title: Nouzové vypnutí
description: pwa:disable a pwa:enable – jak odstranit service worker ze všech zařízení bez nasazení.
---

# Nouzové vypnutí

Rozbitý service worker je horší než rozbitý server: zůstává v prohlížečích a obsluhuje stránky i
po opravě na serveru. Proto balíček umí SW vypnout na všech zařízeních jedním příkazem.

## Použití

```bash
php artisan pwa:disable          # zeptá se; --force bez otázky
php artisan pwa:enable
```

## Co se stane

1. Příznak se uloží do cache (`sw.kill_switch_store`, jinak výchozí store). Žádné nasazení.
2. `/sw.js` začne vracet minimální SW (`resources/sw/kill-switch.js`):
   - při instalaci `skipWaiting()`,
   - při aktivaci smaže všechny cache `pwa-*`, zavolá `registration.unregister()` a otevřená okna
     znovu načte ze sítě.
3. `<x-pwa::head/>` předá klientovi `enabled: false`; klient případné registrace odstraní sám.
4. Manifest zůstává, nainstalovaná aplikace dál funguje online.

Prohlížeč kontroluje `/sw.js` při každé navigaci (`updateViaCache: 'none'`, `no-cache`), takže
zařízení se SW zbaví při další návštěvě.

## Pozor

- **Push odběry**: odregistrace SW zruší push odběry v prohlížečích. Příkaz na to upozorní, pokud
  je nastavené `sw.import`. Po `pwa:enable` se musí uživatelé k notifikacím přihlásit znovu
  (push systém by měl odběr obnovit při `pwa:resume`).
- **Více serverů**: příznak musí být ve sdíleném úložišti (Redis, databáze, Memcached). S `file` nebo
  `array` storem příkaz varuje.
- **`public/sw.js`**: pokud soubor existuje, webserver ho vrací místo routy a vypnutí nefunguje.
  `pwa:doctor` to hlásí jako chybu.
- **`php artisan cache:clear`** příznak smaže (je v cache). Po vyčištění cache zkontrolujte
  `php artisan about` (sekce Laravel PWA → Kill switch).

## Lokální vývoj

S `PWA_ENABLED=false` (výchozí v `local`) vrací `/sw.js` stejný samodestrukční SW, takže SW z dřívějšího
testování se v prohlížeči sám odstraní.
