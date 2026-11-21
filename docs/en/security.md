---
title: Security
description: Per-user cache separation, cookies, CSP (nonces and hashes), telemetry and what the package deliberately does not do.
---

# Security

## Cache and shared devices

- **HTML only when allowed**: without `X-PWA-Cache: allow` (the `pwa.cache` middleware), the service worker stores no page
  at all. The default is safe.
- **Per-user separation**: pages and API responses live in `pwa-pages-{hash}` and `pwa-rule-{name}-{hash}`.
  The hash is `HMAC-SHA256(user id, APP_KEY)` truncated to 16 characters (the `X-PWA-User` header).
  When the service worker sees a different hash, it deletes the previous user's cache and the offline queue.
- **Logout**: `X-PWA-Logout` + `Clear-Site-Data: "cache"` clears the cache and the queue.
- **Never cached**: responses other than 200, `no-store`, 503, and paths from `cache.never_cache`
  (login, logout, register, broadcasting, livewire, sanctum, `/pwa/*`).

Recommendation: don't put `pwa.cache` on sensitive pages, and send responses with sensitive data with
`Cache-Control: no-store`.

## Cookies

| Cookie | Set by | Contents | Encrypted |
| --- | --- | --- | --- |
| `pwa_display` | client | display mode (`standalone`, `browser` …) | no (excluded from `EncryptCookies`) |
| remember cookie | Laravel | standard remember token | yes, HttpOnly |

`pwa_display` is only a hint. A forged value can at most turn on remember-me at login
(and only when the form does not explicitly turn off the remember field). `?source=pwa` does not turn on remember-me.

## CSP

| Resource | How it passes |
| --- | --- |
| `/pwa/client.js` | `script-src 'self'` + nonce from `Vite::useCspNonce()` |
| `#pwa-config` | `type="application/json"`, CSP does not execute it |
| `<x-pwa::nav/>` inline script and style | nonce |
| offline page | nonce (the precached response also carries its own CSP header) |
| maintenance screen | prerendered, no nonce → hashes |
| client status bar | styles via CSSOM (`element.style`), CSP does not block them |

Maintenance screen hashes:

```php
use NyonCode\LaravelPwa\Facades\Pwa;

$hashes = Pwa::cspHashes();
// ['script-src' => ["'sha256-…'"], 'style-src' => ["'sha256-…'"]]
```

The maintenance screen script contains no data from PHP, so its hash is stable. The style contains the colors
from `theme`: recompute the hashes after you change them. Example with `spatie/laravel-csp`:

```php
foreach (Pwa::cspHashes() as $directive => $values) {
    foreach ($values as $hash) {
        $policy->add($directive === 'script-src' ? Directive::SCRIPT : Directive::STYLE, $hash);
    }
}
```

Other directives: `worker-src 'self'`, `manifest-src 'self'`, `connect-src 'self'` (keep-alive,
ping, telemetry).

## Telemetry

`POST /pwa/telemetry`:

- no CSRF (the service worker sends it without cookies), rate limited to 30 requests per minute,
- validation: `source` `sw|client`, at most `telemetry.max_events` events, message up to 500 characters, stack
  up to 2000,
- dispatches the `TelemetryReceived` event ([Events](events.md)); the default listener writes a `warning`
  prefixed with `[pwa]` to `telemetry.channel` (or the default log); turn it off with `telemetry.log = false`,
- includes the User-Agent (300 characters) and no personal data.

To turn it off, set `telemetry.enabled = false`.

## Push notifications

The optional push module follows the same per-user rule: logout removes the session's
subscriptions, a device where someone else is signed in shows only a neutral notification, and the
server sends only to the push services in `push.endpoints`. Details in
[Push notifications](push.md#server-side-safety).

## What the package deliberately does not do

- It does not clear `storage` on logout (that would remove the service worker and the push subscription).
- It does not queue classic POST forms (navigations).
- It does not touch other domains.
- It does not use localStorage for anything that login or data depends on.

## Reporting vulnerabilities

ondrej@nyoncode.cz, see `SECURITY.md`.
