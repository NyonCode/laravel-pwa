---
title: Bezpečnost
description: Oddělení uživatelů v cache, cookies, CSP (nonce a hashe), telemetrie a co balíček záměrně nedělá.
---

# Bezpečnost

## Cache a sdílená zařízení

- **HTML jen na povolení**: bez `X-PWA-Cache: allow` (middleware `pwa.cache`) SW žádnou stránku
  neuloží. Výchozí stav je tedy bezpečný.
- **Oddělení uživatelů**: stránky a API odpovědi jsou v `pwa-pages-{hash}` a `pwa-api-{hash}`.
  Hash je `HMAC-SHA256(id uživatele, APP_KEY)` zkrácený na 16 znaků (hlavička `X-PWA-User`).
  Když SW uvidí jiný hash, smaže cache předchozího uživatele i frontu offline akcí.
- **Odhlášení**: `X-PWA-Logout` + `Clear-Site-Data: "cache"` smaže cache a frontu.
- **Nikdy do cache**: odpovědi jiné než 200, `no-store`, 503, cesty z `cache.never_cache`
  (login, logout, register, broadcasting, livewire, sanctum, `/pwa/*`).

Doporučení: na citlivé stránky `pwa.cache` nedávejte; odpovědi s citlivými daty posílejte s
`Cache-Control: no-store`.

## Cookies

| Cookie | Kdo | Obsah | Šifrovaná |
| --- | --- | --- | --- |
| `pwa_display` | klient | režim zobrazení (`standalone`, `browser` …) | ne (vyjmuta z `EncryptCookies`) |
| remember cookie | Laravel | standardní remember token | ano, HttpOnly |

`pwa_display` je jen nápověda; podvržená hodnota může nanejvýš zapnout remember-me při přihlášení
(a jen když formulář pole remember výslovně nevypne). `?source=pwa` remember-me nezapne.

## CSP

| Zdroj | Jak projde |
| --- | --- |
| `/pwa/client.js` | `script-src 'self'` + nonce z `Vite::useCspNonce()` |
| `#pwa-config` | `type="application/json"`, CSP nespouští |
| `<x-pwa::nav/>` inline skript a styl | nonce |
| offline stránka | nonce (precachovaná odpověď nese i svou CSP hlavičku) |
| obrazovka údržby | předrenderovaná, bez nonce → hashe |
| lišta klienta | styly přes CSSOM (`element.style`), CSP je neblokuje |

Hashe obrazovky údržby:

```php
use NyonCode\LaravelPwa\Facades\Pwa;

$hashes = Pwa::cspHashes();
// ['script-src' => ["'sha256-…'"], 'style-src' => ["'sha256-…'"]]
```

Skript obrazovky údržby neobsahuje data z PHP, takže jeho hash je stabilní. Styl obsahuje barvy
z `theme`: po jejich změně hashe přepočítejte. Příklad se `spatie/laravel-csp`:

```php
foreach (Pwa::cspHashes() as $directive => $values) {
    foreach ($values as $hash) {
        $policy->add($directive === 'script-src' ? Directive::SCRIPT : Directive::STYLE, $hash);
    }
}
```

Další direktivy: `worker-src 'self'`, `manifest-src 'self'`, `connect-src 'self'` (keep-alive,
ping, telemetrie).

## Telemetrie

`POST /pwa/telemetry`:

- bez CSRF (posílá ji SW bez cookies), rate limit 30 požadavků za minutu,
- validace: `source` `sw|client`, max. `telemetry.max_events` událostí, zpráva do 500 znaků, stack
  do 2000,
- zapisuje `warning` s prefixem `[pwa]` do `telemetry.channel` (nebo výchozího logu),
- obsahuje User-Agent (300 znaků), žádné osobní údaje.

Vypnutí: `telemetry.enabled = false`.

## Co balíček záměrně nedělá

- Nemaže `storage` při odhlášení (zrušilo by SW a push odběr).
- Neukládá klasické POST formuláře do fronty (navigace).
- Nezasahuje do cizích domén.
- Nepoužívá localStorage pro nic, na čem závisí přihlášení nebo data.

## Hlášení zranitelností

ondrej@nyoncode.cz, viz `SECURITY.md`.
