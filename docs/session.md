---
title: Trvalé přihlášení
description: Remember-me v nainstalované aplikaci, keep-alive session, obnova CSRF tokenu, odhlášení a specifika iPhonu.
---

# Trvalé přihlášení

Cíl: aplikace nainstalovaná na telefonu zůstane přihlášená týdny a notifikace chodí i při zavřené
aplikaci. Webová aplikace nemůže běžet trvale na pozadí; „stále online“ znamená, že notifikace
doručuje systém a při každém otevření se session obnoví a data načtou.

## Jak balíček pozná nainstalovanou aplikaci

`Pwa::isStandaloneRequest($request)` vrací `true`, když platí cokoli z toho:

| Signál | Odkud |
| --- | --- |
| hlavička `X-PWA-Display` = `standalone`, `minimal-ui`, `window-controls-overlay`, `fullscreen`, `tabbed` | keep-alive a adaptéry klienta |
| cookie `pwa_display` se stejnou hodnotou | klient ji nastavuje při každém startu (nešifrovaná, viz [Bezpečnost](security.md)) |
| `?source=pwa` | `start_url` |
| session `pwa.standalone` | nastaví `PwaWebMiddleware` po prvním `?source=pwa` |

`Pwa::isStandaloneClient($request)` bere jen první dva signály (hlavička a cookie od klienta).
`?source=pwa` i označení session se dají podstrčit odkazem, proto podle nich balíček nerozhoduje
o ničem, co se týká bezpečnosti.

## Remember-me: `pwa.remember`

```php
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('pwa.remember');
```

V nainstalované aplikaci (`Pwa::isStandaloneClient()`) doplní do požadavku `remember=on` (název pole:
`session.remember_field`). Pole, které formulář pošle výslovně jako nepravdivé (`0`, `false`, `off`),
nepřepíše: volba uživatele má přednost. Nezaškrtnutý checkbox se neposílá, takže ho middleware doplní.
Kontroler pak volá `Auth::attempt($credentials, $request->boolean('remember'))` jako obvykle a
Laravel nastaví HttpOnly remember cookie ze serveru. Prohlížeče drží cookies nejvýš 400 dní.

Breeze / Jetstream: `LoginRequest` čte `$this->boolean('remember')`, takže stačí middleware.
Inertia (`useForm({ remember: false })`) posílá `false` vždy: výchozí hodnotu nastavte podle
klienta, např. `remember: window.Pwa?.isInstalled() ?? false`.
Fortify: přidejte middleware na routu `login.store` (vlastní routa nebo `Fortify::ignoreRoutes()`).

Vypnutí: `session.standalone_remember = false`.

## Keep-alive

Klient volá `GET /pwa/session`:

- při návratu do aplikace (`visibilitychange`, `pageshow` z bfcache),
- každých `session.keepalive_minutes`, když je aplikace vidět,
- po odpovědi 419.

Odpověď:

```json
{ "authenticated": true, "user": "3f1c9a0b7d2e4f61", "csrf": "…", "lifetime": 120 }
```

Routa běží ve skupině `web`, takže prodlouží session. `user` je HMAC hash ID, ne ID.

## Obnova CSRF

S `session.refresh_csrf = true` klient po každém keep-alive propíše token do:

- `meta[name="csrf-token"]`,
- všech `input[name="_token"]`,
- `window.axios.defaults.headers.common['X-CSRF-TOKEN']`,
- `window.livewireScriptConfig.csrf`,

a vyšle událost `pwa:csrf`. Navíc obalí `window.fetch` a přidá interceptor do `window.axios` (je-li):
odpověď 419 na ne-GET požadavek na vlastní doménu jednou zopakuje s novým tokenem (hlavička
`X-CSRF-TOKEN`, `_token` ve `FormData`).

## Odhlášení

Událost `Illuminate\Auth\Events\Logout` označí požadavek a odpověď dostane:

```
X-PWA-Logout: 1
Clear-Site-Data: "cache"
X-PWA-User: guest
```

Service worker smaže stránky a API odpovědi uživatele i frontu a pošle klientům `logout`
(událost `pwa:logout`). Záměrně se nemaže `storage`: to by odregistrovalo service worker a zrušilo
push odběr. Push systém může na `pwa:logout` odběr smazat sám.

Údržba, výpadek sítě ani vypršení session uživatele neodhlásí; remember cookie session obnoví.

## iPhone a iPad

- Aplikace na ploše má podle WebKitu vlastní počítadlo dní, oddělené od Safari, takže 7denní limit
  ITP pro data zapsaná skriptem se počítá podle používání aplikace. Existuje ale hlášení odhlášení
  po 7 dnech v aplikaci na ploše (WebKit bug 237350, iOS 15). Proto přihlášení stojí výhradně na
  HttpOnly cookie ze serveru, nikdy na localStorage nebo cookie zapsané JavaScriptem.
- Aplikace na ploše může mít jiné cookies než Safari: uživatel se po instalaci přihlásí v ní.
- Doporučená kontrola před vydáním: přihlášení po 8, 14 a 30 dnech bez otevření aplikace.

## Doporučené nastavení

```dotenv
SESSION_DRIVER=database    # nebo redis; array a cookie nepoužívat
SESSION_LIFETIME=10080     # 7 dní; s remember-me hlavně kvůli CSRF
SESSION_SECURE_COOKIE=true
```

`pwa:doctor` hlásí `array` driver jako chybu a chybějící `pwa.remember` jako varování.
