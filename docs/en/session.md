---
title: Persistent login
description: Remember-me in the installed app, session keep-alive, CSRF token refresh, logout and iPhone specifics.
---

# Persistent login

The goal: an app installed on a phone stays logged in for weeks, and notifications arrive even when
the app is closed. A web app cannot run in the background permanently; "always online" means the
operating system delivers notifications, and every time the app opens, the session is refreshed and
the data is reloaded.

## How the package detects the installed app

`Pwa::isStandaloneRequest($request)` returns `true` when any of these is true:

| Signal | Source |
| --- | --- |
| `X-PWA-Display` header = `standalone`, `minimal-ui`, `window-controls-overlay`, `fullscreen`, `tabbed` | keep-alive and client adapters |
| `pwa_display` cookie with the same value | the client sets it on every launch (unencrypted, see [Security](security.md)) |
| `?source=pwa` | `start_url` |
| `pwa.standalone` in the session | set by `PwaWebMiddleware` after the first `?source=pwa` |

`Pwa::isStandaloneClient($request)` only considers the first two signals (the header and cookie
sent by the client). Both `?source=pwa` and the session mark can be planted with a link, so the
package never bases any security decision on them.

## Remember-me: `pwa.remember`

```php
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('pwa.remember');
```

In the installed app (`Pwa::isStandaloneClient()`), the middleware adds `remember=on` to the request
(field name: `session.remember_field`). It does not overwrite a field that the form explicitly sends
as false (`0`, `false`, `off`): the user's choice wins. An unchecked checkbox is not sent at all, so
the middleware adds it. Your controller then calls
`Auth::attempt($credentials, $request->boolean('remember'))` as usual, and Laravel sets the HttpOnly
remember cookie from the server. Browsers keep cookies for 400 days at most.

Breeze / Jetstream: `LoginRequest` reads `$this->boolean('remember')`, so the middleware is all you need.
Inertia (`useForm({ remember: false })`) always sends `false`: set the default based on the client,
for example `remember: window.Pwa?.isInstalled() ?? false`.
Fortify: add the middleware to `config/fortify.php` (`'middleware' => ['web', 'pwa.remember']`).
Livewire logins post to `/livewire/update`: set `remember` in the component ([Recipes](recipes.md#livewire)).

To turn it off: `session.standalone_remember = false`.

## Keep-alive

The client calls `GET /pwa/session`:

- when the user returns to the app (`visibilitychange`, `pageshow` from the bfcache),
- every `session.keepalive_minutes` while the app is visible,
- after a 419 response.

Response:

```json
{ "authenticated": true, "user": "3f1c9a0b7d2e4f61", "csrf": "…", "lifetime": 120 }
```

The route runs in the `web` group, so it extends the session. `user` is an HMAC hash of the ID, not
the ID itself.

## CSRF refresh

With `session.refresh_csrf = true`, after every keep-alive the client writes the token to:

- `meta[name="csrf-token"]`,
- every `input[name="_token"]`,
- `window.axios.defaults.headers.common['X-CSRF-TOKEN']`,
- `window.livewireScriptConfig.csrf`,

and dispatches the `pwa:csrf` event. It also wraps `window.fetch` and adds an interceptor to
`window.axios` (if present): a 419 response to a non-GET same-origin request is retried once with
the new token (the `X-CSRF-TOKEN` header, `_token` in `FormData`).

## Logout

The `Illuminate\Auth\Events\Logout` event marks the request, and the response gets:

```
X-PWA-Logout: 1
Clear-Site-Data: "cache"
X-PWA-User: guest
```

The service worker deletes the user's pages, API responses and offline queue, and sends `logout`
to its clients (the `pwa:logout` event). It deliberately does not clear `storage`: that would
unregister the service worker and cancel the push subscription. Your push system can delete the
subscription itself on `pwa:logout`.

Maintenance mode, a network failure or an expired session do not log the user out; the remember
cookie restores the session.

## iPhone and iPad

- According to WebKit, a home screen app has its own day counter, separate from Safari, so ITP's
  7-day limit for script-written data counts app usage. There is, however, a report of a logout
  after 7 days in a home screen app (WebKit bug 237350, iOS 15). That is why the login relies
  solely on an HttpOnly cookie set by the server, never on localStorage or a cookie written by
  JavaScript.
- A home screen app can have different cookies than Safari: the user logs in inside the app after
  installing it.
- Recommended check before release: still logged in after 8, 14 and 30 days without opening the app.

## Recommended settings

```dotenv
SESSION_DRIVER=database    # or redis; do not use array or cookie
SESSION_LIFETIME=10080     # 7 days; with remember-me mostly for CSRF
SESSION_SECURE_COOKIE=true
```

`pwa:doctor` reports the `array` driver as an error and a missing `pwa.remember` as a warning.
