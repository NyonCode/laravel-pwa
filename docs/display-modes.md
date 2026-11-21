---
title: Režim zobrazení
description: minimal-ui na desktopu, standalone na telefonu a tabletu; strategie override, adaptive a fixed a tlačítka Zpět a Obnovit.
---

# Režim zobrazení

Jedna aplikace na jedné adrese, ale jiné okno podle zařízení: na desktopu `minimal-ui` s tlačítky
Zpět a Obnovit, na telefonu a tabletu `standalone` na celou obrazovku.

## Režimy

| Režim | Jak vypadá |
| --- | --- |
| `fullscreen` | celá obrazovka bez systémových lišt (hry, kiosky) |
| `standalone` | vlastní okno jako nativní aplikace, bez adresního řádku |
| `minimal-ui` | vlastní okno s minimem ovládání: Zpět, Obnovit, URL (Edge a Chrome na desktopu) |
| `browser` | běžná karta prohlížeče |
| `window-controls-overlay` | aplikace kreslí i titulkový pruh (jen `display_override`, Chromium desktop) |
| `tabbed` | karty uvnitř aplikace (jen `display_override`, Chromium desktop) |

## Strategie `override` (výchozí)

```json
{ "display": "standalone", "display_override": ["minimal-ui"] }
```

| Zařízení | Výsledek | Proč |
| --- | --- | --- |
| Windows, macOS, Linux, ChromeOS – Edge, Chrome | `minimal-ui` | Chromium na desktopu čte `display_override` |
| Android telefon a tablet – Chrome, Samsung Internet | `standalone` | Chrome na Androidu `minimal-ui` z override nepoužije (test z Chrome 91) |
| iPhone, iPad | `standalone` | Safari `display_override` ignoruje |
| Mac – Safari v Docku | `standalone` | tamtéž |
| Firefox na Windows | vlastní okno bez lišt | Firefox manifest pro režim nečte |

Výhoda: manifest je pro všechny stejný, cachuje se (`Cache-Control: public`) a nepotřebuje detekci
zařízení. Chování Androidu ověřte na skutečném zařízení; kdyby se lišilo, přepněte na `adaptive`.

## Strategie `adaptive`

```php
'display' => ['strategy' => 'adaptive', 'value' => 'standalone', 'desktop' => 'minimal-ui'],
```

Server rozhodne při každém požadavku na manifest:

1. Hlavička `Sec-CH-UA-Platform` (Client Hints v Chromiu): Windows, macOS, Linux, Chrome OS = desktop.
2. Bez ní User-Agent: `Windows NT`, `X11; Linux`, `CrOS` = desktop.
3. Vše ostatní = `value`.

Safari se hlásí vždy jako Mac (i na iPadu), proto bez Client Hints macOS za desktop nepovažujeme a
Safari dostane `standalone`. Android tablety často posílají desktopový User-Agent, ale Client Hints
řeknou `Android`, takže dostanou `standalone`.

Manifest pak posílá `Vary: Sec-CH-UA-Platform, User-Agent`, `Accept-CH` a `Cache-Control: private`.
Pozor na CDN, která `Vary` nerespektuje.

## Strategie `fixed`

```php
'display' => ['strategy' => 'fixed', 'value' => 'standalone'],
```

Jen `display`, bez `display_override`.

## Další režimy na desktopu

```php
Pwa::manifest()->windowControlsOverlay(); // display_override: [window-controls-overlay, minimal-ui]
Pwa::manifest()->tabbed(['new_tab_button' => ['url' => '/orders/create']]);
```

Oba se přidávají před `minimal-ui`, takže prohlížeč bez jejich podpory spadne na `minimal-ui`.
Ve WCO aplikace kreslí titulkový pruh sama: použijte CSS proměnné `env(titlebar-area-x)`,
`env(titlebar-area-width)`, `env(titlebar-area-height)` a `app-region: drag`.

## Tlačítka Zpět a Obnovit

V `minimal-ui` je kreslí prohlížeč. Kde chybí, vykreslí je [`<x-pwa::nav/>`](components.md#x-pwanav):

| Situace | Tlačítka |
| --- | --- |
| Edge / Chrome v `minimal-ui` | nativní, komponenta skrytá |
| Edge / Chrome s WCO | komponenta v titulkovém pruhu |
| Safari v Docku, Firefox, desktop ve `standalone` | komponenta v hlavičce aplikace |
| telefon, tablet | skrytá (systémové gesto zpět, pull-to-refresh) |

## Zjištění režimu v aplikaci

CSS:

```css
@media (display-mode: standalone) { .only-in-browser { display: none; } }
@media (display-mode: minimal-ui) { /* desktop okno */ }
```

JavaScript: `window.Pwa.displayMode()` vrací `window-controls-overlay`, `tabbed`, `fullscreen`,
`standalone`, `minimal-ui` nebo `browser`.

PHP: `Pwa::isStandaloneRequest($request)` (hlavička `X-PWA-Display`, cookie `pwa_display`,
`?source=pwa` nebo dřívější označení session).

## Změna režimu po instalaci

Prohlížeče manifest nainstalované aplikace kontrolují periodicky (Chrome zhruba jednou denně při
spuštění). Změna režimu se proto projeví se zpožděním; `id` nikdy neměňte.
