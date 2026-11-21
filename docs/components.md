---
title: Blade komponenty
description: <x-pwa::head/>, <x-pwa::nav/> a <x-pwa::install/> – co vykreslují, atributy a úpravy vzhledu.
---

# Blade komponenty

Všechny komponenty jsou anonymní Blade komponenty v namespace `pwa`. Úpravy:
`php artisan vendor:publish --tag="pwa::views"` a editace v `resources/views/vendor/pwa/components/`.

## `<x-pwa::head/>`

Patří do `<head>`. Vykreslí:

```html
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0d6efd" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b1220" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Moje aplikace">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="/pwa/icons/apple-touch-icon.png">
<script type="application/json" id="pwa-config">{…}</script>
<script type="module" src="/pwa/client.js?v=…" nonce="…"></script>
```

- `apple-touch-icon` jen pokud soubor existuje.
- `#pwa-config` nese konfiguraci klienta (`Pwa::clientConfig()`); typ `application/json` CSP neblokuje.
- Klientský skript má `nonce` z `Vite::useCspNonce()`, když ho aplikace nastavuje.
- `?v=` je hash souboru, takže `Cache-Control: immutable` je bezpečný.

Viewport (`viewport-fit=cover`) a `csrf-token` patří do layoutu aplikace, ne do komponenty.

## `<x-pwa::nav/>`

Tlačítka Zpět a Obnovit pro desktop tam, kde je prohlížeč nekreslí. Viz
[Režim zobrazení](display-modes.md#tlačítka-zpět-a-obnovit).

| Prop | Výchozí | Popis |
| --- | --- | --- |
| `start-url` | `config('pwa.start_url')` | Na této stránce je Zpět neaktivní. |

Zobrazí se, když platí `(pointer: fine)`, šířka ≥ `desktop_nav.min_width` a režim je `standalone`
nebo `window-controls-overlay`. Ve WCO se umístí do titulkového pruhu (`position: fixed` podle
`env(titlebar-area-*)`), jinak zůstane v toku stránky.

Styl: třídy `.pwa-nav` a `.pwa-nav__btn`, barva z `currentColor`. Reaguje na `popstate`,
`livewire:navigated` a `inertia:navigate`.

## `<x-pwa::install/>`

```blade
<x-pwa::install class="btn btn-primary">Nainstalovat aplikaci</x-pwa::install>
<x-pwa::install :dismissible="false"/>
```

| Prop | Výchozí | Popis |
| --- | --- | --- |
| slot | přeložené „Nainstalovat aplikaci“ | text tlačítka |
| `dismissible` | `true` | tlačítko Zavřít v iOS nápovědě |
| atributy | – | předají se na `<button>` (třída se sloučí s `pwa-install`) |

Vykreslí skryté tlačítko `[data-pwa-install]` a skrytou nápovědu `[data-pwa-ios-hint]`. Klient je
odkryje, když:

- aplikace ještě není nainstalovaná,
- uživatel ji navštívil aspoň `install.min_visits`krát,
- ji v posledních `install.dismiss_days` dnech neodmítl,
- a prohlížeč instalaci nabízí (`beforeinstallprompt` nebo `navigator.install`), resp. jde o
  iPhone / iPad (nápověda).

Vlastní tlačítko kdekoli: atribut `data-pwa-install` na libovolném prvku; zavření nápovědy
`data-pwa-install-dismiss`.

## Offline a údržba

Obrazovky `pwa::offline` a `pwa::maintenance` nejsou komponenty, ale celé stránky. Po publikování
views je upravte v `resources/views/vendor/pwa/`. Musí zůstat bez externích zdrojů (CSS, fonty,
obrázky inline): offline nejsou dostupné a během údržby ani assety.
