---
title: JavaScript klient
description: window.Pwa – API, události pwa:*, lišty, instalace, badge a jak klient registruje service worker.
---

# JavaScript klient

`/pwa/client.js` načte `<x-pwa::head/>` jako ES modul. Nemá závislosti a nepotřebuje build.

## Co dělá po načtení

1. Zvýší počítadlo návštěv (localStorage `pwa:visits`).
2. Nastaví cookie `pwa_display` s režimem zobrazení.
3. Obalí `fetch` a `window.axios` pro opakování po 419 (s `session.refresh_csrf`, jen požadavky na vlastní origin).
4. Zapne adaptéry Livewire a Inertia.
5. Zaregistruje service worker, nebo ho odregistruje (`enabled: false`).
6. Spustí keep-alive interval.
7. Vyšle `pwa:ready`.

## `window.Pwa`

| Člen | Vrací | Popis |
| --- | --- | --- |
| `config` | object | konfigurace z `#pwa-config` |
| `displayMode()` | string | `window-controls-overlay`, `tabbed`, `fullscreen`, `standalone`, `minimal-ui`, `browser` |
| `isInstalled()` | bool | `displayMode() !== 'browser'` (na iOS i `navigator.standalone`) |
| `isIos()` | bool | iPhone, iPod, iPad (i iPad hlásící se jako Mac) |
| `install()` | `Promise<string>` | `accepted`, `dismissed`, `unavailable`; použije `navigator.install()` nebo uloženou výzvu |
| `canInstall()` | bool | instalace má teď smysl: je k dispozici výzva, nebo platí iOS návod |
| `update()` | Promise | ručně zkontroluje novou verzi SW |
| `updateAvailable()` | bool | čeká nová verze |
| `applyUpdate()` | bool | aktivuje čekající verzi a po jejím převzetí stránku znovu načte; `false`, když nic nečeká |
| `refreshUi()` | – | ukáže nebo skryje prvky `[data-pwa-*]` přidané po načtení (instalace, aktualizace, push, odchozí schránka) |
| `keepAlive()` | `Promise<object\|null>` | zavolá `/pwa/session` hned |
| `warm()` | `Promise<object\|null>` | stáhne stránky k předehřátí hned; `{count, skipped, failed}` ([Offline](offline.md#předehřátí)) |
| `outbox()` | `Promise<object[]>` | změny, které server při odeslání odmítl, každá s `retry()` a `discard()` ([Offline](offline.md#odchozí-schránka)) |
| `version()` | `Promise<string\|null>` | verze aktivního SW |
| `on(event, fn)` | function | posluchač bez prefixu `pwa:`; vrací funkci pro odhlášení |
| `badge.set(n)` | Promise | Badging API (iOS 16.4+, Android, desktop) |
| `badge.clear()` | Promise | |
| `push.*` | | `supported()`, `permission()`, `state()`, `subscribe()`, `unsubscribe()`, `toggle()`, `subscription()`; viz [Push notifikace](push.md#přihlášení-k-odběru-v-prohlížeči) |

```js
if (!Pwa.isInstalled() && Pwa.isIos()) showIosHelp()

const off = Pwa.on('queue', ({ count }) => (queueBadge.textContent = count || ''))
Pwa.badge.set(unreadCount)
console.log(await Pwa.version())
```

## Události

Všechny jako `CustomEvent` na `document` (`pwa:jméno`) i přes `Pwa.on('jméno')`.

| Událost | `detail` | Kdy |
| --- | --- | --- |
| `pwa:ready` | `{displayMode}` | klient načten |
| `pwa:resume` | `{displayMode}` | návrat do aplikace (viditelnost, bfcache) |
| `pwa:online` | `{}` | obnovení připojení |
| `pwa:offline` | `{}` | SW nezvládl navigaci ani akci kvůli síti |
| `pwa:maintenance` | `{retryAfter}` | SW dostal 503 |
| `pwa:logout` | `{}` | SW zpracoval odhlášení |
| `pwa:queue` | `{count, failed}` | změna fronty offline akcí (`count` čeká, `failed` v odchozí schránce) |
| `pwa:queue-full` | `{count}` | akce se neuložila do fronty kvůli `offline.queue_max` |
| `pwa:replayed` | `{url, status}` | akce z fronty odeslána (s jakýmkoli stavem) |
| `pwa:replay-failed` | `{id, url, method, status, reason, message, errors, kept}` | server akci z fronty odmítl, nebo vypršela; `kept`, když zůstává v odchozí schránce |
| `pwa:update-available` | `{worker}` | čeká nová verze SW |
| `pwa:activated` | `{version}` | aktivovala se verze SW |
| `pwa:installable` | `{prompt, ios}` | instalace je možná (výzva, nebo iOS návod) |
| `pwa:installed` | `{}` | `appinstalled` |
| `pwa:csrf` | `{token}` | nový CSRF token propsán |
| `pwa:csrf-refreshed` | `{}` | Livewire 419 vyřešeno |
| `pwa:identity` | `{user}` | přihlásil se jiný uživatel (hash) |
| `pwa:warmed` | `{count, skipped, failed}` | předehřátí skončilo |
| `pwa:push` | `{state, lost?, error?}` | push odběr se změnil (`subscribed`, `unsubscribed`, `denied`) |
| `pwa:push-received` | `{tag}` | worker zobrazil push notifikaci |
| `pwa:push-renewed` | `{}` | worker obnovil vyměněný odběr |
| `pwa:notification-click` | `{url, action}` | uživatel klikl na notifikaci |

```js
document.addEventListener('pwa:resume', () => refreshDashboard())
document.addEventListener('pwa:logout', () => pushSystem.unsubscribe())
```

## Lišta

Pro offline, údržbu, frontu a aktualizaci klient ukáže malou lištu dole (`.pwa-bar`, `role=status`,
`aria-live=polite`, respektuje safe-area). Texty z `pwa::messages`. Styl se nastavuje přes
`element.style` (CSP-safe). Vlastní UI: lištu skryjte (`.pwa-bar { display: none !important }`)
a reagujte na události. U aktualizací stačí `updates.bar = false` nebo `<x-pwa::update-toast/>` na stránce.

## Aktualizace service workeru

Nová verze čeká, dokud ji uživatel nepřijme; do té doby stránka běží s verzí, se kterou se načetla.
Lišta (nebo [`<x-pwa::update-toast/>`](components.md#x-pwaupdate-toast)) nabídne aktualizaci. Po kliknutí
klient pošle čekajícímu workeru `SKIP_WAITING` a po `controllerchange` stránku znovu načte. Bez kliknutí
se nová verze aktivuje, až se zavřou všechna okna aplikace.

Vlastní UI: nastavte `updates.bar` na `false`, poslouchejte `pwa:update-available` a volejte `Pwa.applyUpdate()`:

```js
Pwa.on('update-available', () => showMyToast({ onAccept: () => Pwa.applyUpdate() }))
```

`SKIP_WAITING` neposílejte sami: klient by nevěděl, že má stránku znovu načíst, a stránka by spouštěla
starý JavaScript proti novému workeru.

## Úložiště

| Klíč localStorage | Obsah |
| --- | --- |
| `pwa:visits` | počet návštěv |
| `pwa:install-dismissed` | čas odmítnutí instalace |
| `pwa:warm` | hash uživatele a čas posledního předehřátí |
| `pwa:push` | endpoint, hash uživatele a čas posledního potvrzení push odběru tohoto prohlížeče |

V sessionStorage (pro každou záložku zvlášť) si `pwa:update-dismissed` pamatuje „Později“ u toastu
aktualizace: na dalších stránkách této záložky zůstane toast skrytý a vrátí se s novější verzí nebo
v nové záložce.

Přístup je v `try/catch` (soukromý režim, plné úložiště). Přihlášení na localStorage nikdy nezávisí.
