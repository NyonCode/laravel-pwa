---
title: Přehled
description: Co balíček nyoncode/laravel-pwa dělá, z čeho se skládá a kde začít.
---

# Laravel PWA

`nyoncode/laravel-pwa` udělá z Laravel aplikace instalovatelnou Progressive Web App: jedna aplikace
na jedné adrese, která na desktopu běží v okně s tlačítky Zpět a Obnovit, na telefonu a tabletu na
celou obrazovku, funguje bez připojení, přežije údržbu a nainstalovaná zůstane přihlášená.

Vyzkoušejte si ho v [živém demu](https://nyoncode.github.io/laravel-pwa/demo/): nainstalujte ho, přepněte
se do offline režimu a přijměte aktualizaci.

Podporuje Laravel 12 a 13 a PHP 8.2+. Postavený je na
[`nyoncode/laravel-package-toolkit`](https://github.com/NyonCode/laravel-package-toolkit).

## Z čeho se skládá

| Část | Co dělá | Stránka |
| --- | --- | --- |
| Manifest | `/manifest.webmanifest` z configu a fluent API, režim zobrazení, ikony, integrace do OS | [Manifest](manifest.md), [Režim zobrazení](display-modes.md) |
| Komponenty | `<x-pwa::head/>`, `<x-pwa::nav/>`, `<x-pwa::install/>` | [Komponenty](components.md) |
| Service worker | `/sw.js` sestavený z Vite buildu, strategie cache, aktualizace | [Service worker](service-worker.md) |
| Offline | offline obrazovka, stránky dostupné offline, fronta akcí | [Offline](offline.md) |
| Session | remember-me v nainstalované aplikaci, keep-alive, obnova CSRF | [Trvalé přihlášení](session.md) |
| Údržba | obrazovka údržby, `pwa:down`, rozlišení 503 od výpadku sítě | [Údržba](maintenance.md) |
| Nouzové vypnutí | `pwa:disable` odstraní service worker ze všech zařízení | [Nouzové vypnutí](kill-switch.md) |
| Klient | `window.Pwa`, události `pwa:*` | [JavaScript klient](client.md) |
| Push | volitelně: kanál pro notifikace, `Pwa.push`, odběry po zařízeních; nebo místo pro vlastní push systém | [Push notifikace](push.md) |
| AI | MCP server, skill a reference pro coding agenty | [AI agenti](ai.md) |

## Co balíček nedělá

- **Sám od sebe push neposílá.** Modul push je vypnutý, dokud ho nezapnete, a prohlížeč se na
  oprávnění zeptá, až když váš kód zavolá `Pwa.push.subscribe()`. Viz [Push notifikace](push.md).
- **Nebuildí frontend.** Assety bere z Vite buildu aplikace (`public/build/manifest.json`).
- **Nerozhoduje o tom, co je offline.** Žádná stránka se neuloží, dokud to server nepovolí
  middlewarem `pwa.cache`.

## Nejrychlejší cesta

```bash
composer require nyoncode/laravel-pwa
php artisan pwa:install
php artisan pwa:icons resources/pwa/icon.png
```

```blade
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <x-pwa::head/>
</head>
```

Pokračujte na [Instalaci](installation.md).
