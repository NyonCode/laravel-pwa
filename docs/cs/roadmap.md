---
title: Roadmap
description: "Co je v plánu po 1.1: navigace Livewire offline, odchozí schránka pro neodeslané požadavky, doctor pro živý web, podporované verze v roce 2027 a 2.0."
---

# Roadmap

Plány, ne sliby: pořadí i obsah se mohou změnit. Všechno v 1.x zachová veřejné API (viz
[Verzování](versioning.md)); vydání, které by ho rozbilo, počká na 2.0. Nápady a požadavky jsou vítané
v [GitHub issues](https://github.com/NyonCode/laravel-pwa/issues).

## Vydáno

| Verze | Hlavní body |
| --- | --- |
| 1.0 | stabilní veřejné API, UI komponenty, WebKit v e2e, předcache Vite, push po dávkách a statistika |
| 1.0.1–1.0.3 | opravy z reálné aplikace a starter kitů, CI se starter kity, stav push `unavailable` |
| 1.1 | odkazy Inertia na uložené stránky fungují offline, `pwa:push:prune` |

## 1.2: Navigace Livewire a odchozí schránka

- **`wire:navigate` offline.** Livewire další stránku stahuje s hlavičkou `X-Livewire-Navigate` místo
  navigace, takže jí worker dnes z uložených stránek neodpoví – stejná mezera, jakou měla Inertia před
  1.1. Worker bude uložené stránky servírovat i těmto požadavkům, s krokem ve smoke testu Livewire
  starter kitu.
- **Odchozí schránka pro neúspěšná odeslání.** Požadavek z offline fronty, který server odmítne (chyba
  validace 422, 403, 409), se dnes po události `replayed` zahodí. Nově zůstane v odchozí schránce:
  `Pwa.outbox()` ho vypíše se stavem a odpovědí serveru, `retry()` a `discard()` s ním naloží, nová
  událost `replay-failed` a komponenta `<x-pwa::outbox>` ho ukážou uživateli.
- **Limity fronty.** Volitelné maximální stáří a počet požadavků ve frontě (`offline.queue_max_age`,
  `offline.queue_max`), aby se starý požadavek neodeslal po dnech bez vědomí uživatele.

## 1.3: Živý web

- **`pwa:doctor --url=`.** Zkontroluje nasazený web zvenku: HTTPS, manifest a jeho ikony, hlavičky a
  scope workeru (`Service-Worker-Allowed`, hlavičky cache za CDN), hlavičky `X-PWA-*` uložené stránky
  a odpověď při údržbě. Hodí se do deploy pipeline.

## Podporované verze v roce 2027

PHP 8.2 přestane dostávat bezpečnostní opravy na konci roku 2026 a Laravel 12 v únoru 2027. Podle
[politiky podpory](versioning.md#politika-podpory) je první minor vydání po tomto datu přestane podporovat
(PHP 8.3+, Laravel 13+), oznámené v changelogu o vydání dřív. Laravel 14 bude podporovaný hned po
vydání.

## 2.0

Zatím bez data: 2.0 přijde, jen když nějakou změnu nepůjde udělat bez rozbití veřejného API. Do té doby
se sbírají kandidáti: odstranění všeho, co je v 1.x označené jako zastaralé, a přejmenování klíčů
configu, které se rozrostly nekonzistentně, vždy s návodem v `UPGRADE.md`.

## Průběžně

- [CI se starter kity](testing.md) běží každý týden proti nejnovějším starter kitům Laravelu, aby se
  změna u nich projevila dřív, než ji nahlásí uživatelé.
- Nové funkce prohlížečů (Declarative Web Push, členy manifestu) přibývají v minor vydáních, jakmile je
  podporují aspoň dva enginy nebo je jedna platforma potřebuje k instalaci.
