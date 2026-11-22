---
title: Blade komponenty
description: "<x-pwa::head/>, <x-pwa::nav/> a <x-pwa::install/> – co vykreslují, atributy a úpravy vzhledu."
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
<link rel="apple-touch-startup-image" media="screen and (device-width: 402px) and (device-height: 874px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/pwa/icons/splash/apple-splash-1206x2622.png">
<script type="application/json" id="pwa-config">{…}</script>
<script type="module" src="/pwa/client.js?v=…" nonce="…"></script>
```

- `apple-touch-icon` jen pokud soubor existuje; `apple-touch-startup-image` pro každou úvodní
  obrazovku z `pwa:icons --splash`, s media query pro její displej.
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
<x-pwa::install ios="hint" :dismissible="false"/>
```

| Prop | Výchozí | Popis |
| --- | --- | --- |
| slot | přeložené „Nainstalovat aplikaci“ | text tlačítka |
| `ios` | `sheet` | návod pro iPhone / iPad: `sheet` (dva kroky s ikonou Sdílet), `hint` (jeden řádek), `none` |
| `dismissible` | `true` | tlačítko Zavřít v iOS návodu |
| `styles` | `true` | [základní styly](#stylování) |
| slot `ios-hint` | – | vlastní obsah iOS návodu |
| atributy | – | předají se na `<button>` (třída se sloučí s `pwa-install`) |

Vykreslí skryté tlačítko `[data-pwa-install]` a skrytou nápovědu `[data-pwa-ios-hint]`. Klient je
odkryje, když:

- aplikace ještě není nainstalovaná,
- uživatel ji navštívil aspoň `install.min_visits`krát,
- ji v posledních `install.dismiss_days` dnech neodmítl,
- a prohlížeč instalaci nabízí (`beforeinstallprompt` nebo `navigator.install`), resp. jde o
  iPhone / iPad (nápověda).

Vlastní tlačítko kdekoli: atribut `data-pwa-install` na libovolném prvku; zavření nápovědy
`data-pwa-install-dismiss`. Totéž zjistí váš kód přes `Pwa.canInstall()` a událost `pwa:installable`.

## `<x-pwa::update-toast/>`

```blade
<x-pwa::update-toast/>
<x-pwa::update-toast class="my-toast">Aplikace se aktualizovala.<x-slot:apply>Restartovat</x-slot:apply></x-pwa::update-toast>
```

| Prop | Výchozí | Popis |
| --- | --- | --- |
| slot | přeložené „Je k dispozici nová verze.“ | text |
| slot `apply` | přeložené „Aktualizovat“ | text tlačítka, které aktivuje novou verzi |
| `dismissible` | `true` | tlačítko „Později“; v této záložce se toast znovu ukáže až s další novou verzí |
| `styles` | `true` | [základní styly](#stylování) |
| atributy | – | předají se na toast (`role=status`, třída `pwa-toast`) |

Skrytý, dokud nečeká nový service worker. „Aktualizovat“ zavolá `Pwa.applyUpdate()`: nový worker
převezme stránku a ta se znovu načte. S toastem na stránce se vestavěná
[lišta aktualizace](client.md#aktualizace-service-workeru) neukáže. Vlastní markup funguje stejně:
`[data-pwa-update]` (viditelný, dokud aktualizace čeká), `[data-pwa-update-apply]` a `[data-pwa-update-dismiss]`.

## `<x-pwa::push-toggle/>`

```blade
<x-pwa::push-toggle class="btn"/>
<x-pwa::push-toggle>
    <x-slot:enable>Upozorňovat na moje objednávky</x-slot:enable>
</x-pwa::push-toggle>
```

| Prop / slot | Výchozí | Popis |
| --- | --- | --- |
| slot `enable` | přeložené „Zapnout oznámení“ | text tlačítka bez odběru |
| slot `disable` | přeložené „Vypnout oznámení“ | text tlačítka s odběrem |
| slot `denied` | přeložená nápověda | uživatel oznámení zablokoval |
| slot `install-required` | přeložená nápověda | iPhone / iPad v Safari: push funguje jen v aplikaci z plochy |
| `styles` | `true` | [základní styly](#stylování) |
| atributy | – | předají se na `<button>` (třída `pwa-push__toggle`) |

Potřebuje [modul web push](push.md). Kliknutí přihlásí nebo odhlásí odběr tohoto zařízení
(`Pwa.push.toggle()`); dotaz na oprávnění se objeví až tehdy. Obal dostane `data-pwa-push-state`
(`subscribed`, `unsubscribed`, `denied`, `install-required`), tlačítko `aria-pressed` a zobrazí se
odpovídající `[data-pwa-push-label]`. Bez push na serveru nebo v prohlížeči bez push zůstane skrytý.

## Stylování

`install`, `update-toast` a `push-toggle` vypíšou jednou na stránku malý stylopis (s CSP nonce).
Upravte ho CSS proměnnými, nebo předejte `:styles="false"` a nastylujte třídy `pwa-*` sami.
Pravidla používají `:where()`, takže vaše třída vždy vyhraje.

```css
.pwa-ui {
  --pwa-surface: #0f172a;   /* pozadí toastu a iOS návodu */
  --pwa-on-surface: #fff;   /* text */
  --pwa-muted: #cbd5e1;     /* vedlejší text */
  --pwa-action: #2563eb;    /* tlačítka */
  --pwa-radius: 16px;
  --pwa-shadow: 0 8px 24px rgb(0 0 0 / .2);
  --pwa-font: 500 15px/1.4 Inter, sans-serif;
}
```

Veškerá logika je v klientovi (`pwa.js`), komponenty tedy nepotřebují inline skript.
Prvky přidané později (Livewire `wire:navigate`, Inertia, vykreslení ve Vue nebo Reactu) klient najde
po `livewire:navigated`, `inertia:navigate` nebo po zavolání `Pwa.refreshUi()`.

## Vue a React

Blade komponenty jen vykreslí markup s atributy `data-pwa-*`. Ve Vue nebo Reactu vykreslete stejné
atributy, nebo použijte přímo API klienta.

```js
// resources/js/composables/usePwa.js (Vue 3)
import { onMounted, onUnmounted, ref } from 'vue'

export function usePwa() {
  const canInstall = ref(window.Pwa?.canInstall() ?? false)
  const updateAvailable = ref(window.Pwa?.updateAvailable() ?? false)
  const push = ref('unsupported')
  const offs = []

  onMounted(async () => {
    offs.push(Pwa.on('installable', () => (canInstall.value = Pwa.canInstall())))
    offs.push(Pwa.on('installed', () => (canInstall.value = false)))
    offs.push(Pwa.on('update-available', () => (updateAvailable.value = true)))
    offs.push(Pwa.on('push', ({ state }) => (push.value = state)))
    push.value = await Pwa.push.state()
  })
  onUnmounted(() => offs.forEach((off) => off()))

  return { canInstall, updateAvailable, push, install: () => Pwa.install(), update: () => Pwa.applyUpdate(), togglePush: () => Pwa.push.toggle() }
}
```

```jsx
// resources/js/hooks/usePwa.js (React)
import { useEffect, useState } from 'react'

export function usePwa() {
  const [canInstall, setCanInstall] = useState(() => window.Pwa?.canInstall() ?? false)
  const [updateAvailable, setUpdateAvailable] = useState(() => window.Pwa?.updateAvailable() ?? false)
  const [push, setPush] = useState('unsupported')

  useEffect(() => {
    const offs = [
      Pwa.on('installable', () => setCanInstall(Pwa.canInstall())),
      Pwa.on('installed', () => setCanInstall(false)),
      Pwa.on('update-available', () => setUpdateAvailable(true)),
      Pwa.on('push', ({ state }) => setPush(state)),
    ]
    Pwa.push.state().then(setPush)
    return () => offs.forEach((off) => off())
  }, [])

  return { canInstall, updateAvailable, push, install: () => Pwa.install(), update: () => Pwa.applyUpdate(), togglePush: () => Pwa.push.toggle() }
}
```

`window.Pwa` existuje, jakmile se spustí modul klienta; při vykreslování na serveru volejte hook jen
v prohlížeči. `Pwa.push.toggle()` a `Pwa.install()` volejte z obsluhy kliknutí.

## Offline a údržba

Obrazovky `pwa::offline` a `pwa::maintenance` nejsou komponenty, ale celé stránky. Po publikování
views je upravte v `resources/views/vendor/pwa/`. Musí zůstat bez externích zdrojů (CSS, fonty,
obrázky inline): offline nejsou dostupné a během údržby ani assety.
