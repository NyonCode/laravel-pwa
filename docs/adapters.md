---
title: Livewire a Inertia
description: Jak klient řeší výpadek sítě, frontu, údržbu, 419 a návrat do aplikace v Livewire 3 a Inertia.
---

# Livewire a Inertia

Livewire i Inertia načítají data přes `fetch`/XHR, ne navigací. Service worker jim proto offline
obrazovku ani obrazovku údržby nezobrazí a jejich vlastní chybová okna by ukázala surové HTML.
Klient balíčku obsahuje dva malé adaptéry.

| Situace | Livewire 3 | Inertia |
| --- | --- | --- |
| výpadek sítě | lišta „Jste offline“, výchozí okno potlačeno | lišta, `inertia:exception` potlačena |
| akce ve frontě (202) | – (Livewire cesty jsou v `never_cache`) | lišta, `pwa:queue` |
| 503 údržba | `location.reload()` → obrazovka údržby | totéž |
| 419 CSRF | nový token, `pwa:csrf-refreshed`, okno „Page expired“ potlačeno | nový token; automatické zopakování jen přes `fetch` a `window.axios` |
| návrat do aplikace | `pwa:resume` | `pwa:resume`, s `adapters.reload_on_resume` `router.reload()` |

## Livewire 3

Adaptér se zapne na `livewire:init` a používá `Livewire.hook('request', ({ fail }) => …)`.
`/livewire/*` je v `cache.never_cache`: SW do Livewire požadavků nezasahuje a neukládá je do fronty
(stav komponent by po odeslání se zpožděním nemusel sedět).

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
request zopakuje; jinak token jen obnoví a uživatel akci zopakuje.

Formuláře přes `useForm` jsou XHR, takže se při výpadku uloží do fronty a odešlou po připojení.
Kvůli zpožděnému odeslání posílejte s vytvářenými záznamy vlastní UUID.

## Vypnutí

```php
'adapters' => ['livewire' => false, 'inertia' => false],
```

Názvy hooků a událostí ověřte proti verzím Livewire a Inertia, které používáte.
