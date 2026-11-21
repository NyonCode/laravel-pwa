---
title: Podpora platforem
description: Co funguje na iOS, Androidu, desktopovém Chromiu, Safari a Firefoxu a jak to balíček řeší.
---

# Podpora platforem

Stav k listopadu 2026. Balíček vždy detekuje schopnost v prohlížeči, ne jeho značku; co platforma
nemá, tiše vynechá.

| Schopnost | iOS / iPadOS | Android | Desktop Chromium | Safari macOS | Firefox |
| --- | --- | --- | --- | --- | --- |
| Instalace | ručně (Sdílet → Přidat na plochu), od iOS 26 výchozí „Otevřít jako web app“ | výzva | výzva, `navigator.install()` | Přidat do Docku | Windows: hlavní panel (143+) |
| Režim z manifestu | `standalone` | `standalone` | `minimal-ui` | `standalone` | vlastní okno |
| `display_override` | ne | ano (bez minimal-ui) | ano | ne | ne |
| Service worker, offline | ano | ano | ano | ano | ano |
| Navigation Preload | ano | ano | ano | ano | ano |
| Background Sync | ne → fronta se odešle při návratu | ano | ano | ne | ne |
| Badge | 16.4+ | ano | ano | ano | ne |
| Web Push | jen nainstalovaná app (16.4+) | ano | ano | ano | ano |
| shortcuts, share_target | ne | ano | ano | ne | ne |
| file/protocol handlers, WCO, tabbed, launch_handler | ne | ne | ano | ne | ne |
| Persistentní úložiště | vlastní počítadlo dní aplikace | ano | ano | 7 dní ITP mimo Dock | ano |

## iPhone a iPad

- Instalace jen ručně; `<x-pwa::install/>` ukáže nápovědu.
- `theme_color` z manifestu iOS ignoruje; balíček posílá `apple-mobile-web-app-*` meta.
- Výřez a Dynamic Island: `viewport-fit=cover` + `env(safe-area-inset-*)`.
- Aplikace na ploše může mít jiné cookies než Safari.
- Simulátor iOS push neumí; testujte na zařízení.

## Android

- Tablety a skládací telefony mají stejný Chrome; layout řiďte media/container queries.
- Samsung Internet: vlastní instalační UI, testujte zvlášť.
- Maskable ikona je nutná, jinak systém ikonu zmenší do bílého kruhu.

## Desktop

- Edge a Chrome: `minimal-ui` s tlačítky Zpět a Obnovit, volitelně WCO a karty.
- Safari v Docku: `standalone`; tlačítka dodá `<x-pwa::nav/>`.
- Firefox (Windows): web apps bez manifest integrací; push a offline fungují.

## Testovací matice před vydáním

| Zařízení | Prohlížeče |
| --- | --- |
| iPhone (iOS 26+) | Safari, aplikace na ploše |
| iPad | Safari, aplikace na ploše, Stage Manager |
| Android telefon | Chrome, Samsung Internet |
| Android tablet | Chrome |
| Windows | Edge, Chrome, Firefox |
| macOS | Chrome, Safari v Docku |
| ChromeOS | Chrome |

U každého: instalace, režim okna, offline stránka, stránka z cache, fronta, údržba, aktualizace SW,
přihlášení po několika dnech.
