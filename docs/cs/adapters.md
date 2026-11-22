---
title: Livewire a Inertia
description: Jak klient řeší výpadek sítě, frontu, údržbu, 419 a návrat do aplikace v Livewire 3 a 4 a Inertia.
---

# Livewire a Inertia

Livewire i Inertia načítají data přes `fetch`/XHR, ne navigací. Service worker jim proto offline
obrazovku ani obrazovku údržby nezobrazí a jejich vlastní chybová okna by ukázala surové HTML.
Klient balíčku obsahuje dva malé adaptéry.

| Situace | Livewire 3 a 4 | Inertia |
| --- | --- | --- |
| výpadek sítě | lišta „Jste offline. Změna se neodeslala“, výchozí okno potlačeno | lišta „Jste offline“, `inertia:exception` potlačena |
| akce ve frontě (202) | – (Livewire požadavky nejdou do fronty) | lišta, `pwa:queue` |
| 503 údržba | `location.reload()` → obrazovka údržby | totéž |
| 419 CSRF | nový token, `pwa:csrf-refreshed`, okno „Page expired“ potlačeno | nový token; automatické zopakování jen přes `fetch` a `window.axios` |
| návrat do aplikace | `pwa:resume` | `pwa:resume`, s `adapters.reload_on_resume` `router.reload()` |

## Livewire

Adaptér se zapne na `livewire:init`. V Livewire 4 používá `Livewire.interceptRequest()` (`onError`
pro 419 a 503, `onFailure` pro síť), v Livewire 3 `Livewire.hook('request')`. Po 419 klient aktualizuje
i atribut `data-csrf` skriptu Livewire.

SW Livewire požadavky jen propustí na síť (přečte z nich identitu uživatele), neukládá je do cache ani
do fronty: stav komponent by po odeslání se zpožděním nemusel sedět. Pozná je podle hlavičky
`X-Livewire`, takže to platí i pro adresu Livewire 4 `/livewire-{hash}/update`; `cache.never_cache`
obsahuje `/livewire/*` i `/livewire-*`.

Offline stav v šabloně: vestavěné `wire:offline` funguje dál.

```blade
<div wire:offline class="banner">Jste offline</div>
```

Po 419 uživatel akci zopakuje; token je už nový. Obnova dat po návratu:

```js
document.addEventListener('pwa:resume', () => Livewire.dispatch('refresh-data'))
```

## Inertia

Adaptér poslouchá DOM události `inertia:invalid` (odpověď, která není Inertia, např. 503 nebo
202 z fronty) a `inertia:exception` (chyba sítě) a volá `preventDefault()`.

Inertia používá vlastní instanci axios. Pokud ji aplikace vystaví jako `window.axios`, klient po 419
request zopakuje (jen na vlastní origin, token nikdy neodejde jinam); jinak token jen obnoví a
uživatel akci zopakuje.

Formuláře přes `useForm` jsou XHR, takže se při výpadku uloží do fronty a odešlou po připojení.
Kvůli zpožděnému odeslání posílejte s vytvářenými záznamy vlastní UUID.

## Vypnutí

```php
'adapters' => ['livewire' => false, 'inertia' => false],
```

Názvy hooků a událostí ověřte proti verzím Livewire a Inertia, které používáte.
