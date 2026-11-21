---
title: Deployment
description: "Deploy scripts, multiple servers, Octane, CDNs, nginx and Docker: what must hold in production."
---

# Deployment

## Deploy script

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize          # includes pwa:build
php artisan pwa:doctor --ci   # stops the deploy on an error
php artisan migrate --force
```

The order matters: `pwa:build` reads `public/build/manifest.json`, so it must run after `npm run build`.

With maintenance mode:

```bash
php artisan pwa:down --message="Deploying a new version" --retry=60
# … deploy …
php artisan up
```

Zero-downtime tools (Envoyer, Deployer, Forge): run `pwa:build` in the step after the asset build,
inside the new release directory. `bootstrap/cache/pwa/` is part of the release, not of shared storage.

## Multiple servers

| What | Requirement |
| --- | --- |
| `bootstrap/cache/pwa/sw.js` | build it on every server from the same build (or build it once and ship it with the release) |
| kill switch | `PWA_KILL_SWITCH_STORE=redis` (a shared store) |
| session | shared (`database`, `redis`) |

Because the service worker version is a content hash, the same build produces the same file on every
server, and the browser doesn't flip back and forth.

## Octane

The package keeps no state between requests: `/sw.js` reads a prebuilt file, and the manifest is built
from config. Fluent `Pwa::manifest()` calls belong in `boot()` and apply to all requests. Under Octane,
the toolkit handles the provider lifecycle itself.

## CDN and reverse proxy

| Path | Cache |
| --- | --- |
| `/sw.js` | should not be cached (respect the app's `no-cache`) |
| `/manifest.webmanifest` | 1 h; with `adaptive` only if the CDN respects `Vary` |
| `/pwa/client.js?v=…` | long-term (`immutable`) |
| `/pwa/session`, `/pwa/ping`, `/pwa/telemetry` | never |
| `/build/*` | long-term (hash in the file name) |

Cloudflare: add a "Bypass cache" rule for `/sw.js` and `/pwa/*`.

## nginx

The standard Laravel configuration is enough (`try_files $uri $uri/ /index.php?$query_string;`),
because `/sw.js` and the manifest are routes. However, `public/sw.js` and
`public/manifest.webmanifest` must not exist, or they would take precedence.

## Docker and read-only file systems

`pwa:build` writes to `bootstrap/cache/pwa/`. Run it while building the image (after `npm run build`),
or when the container starts. Without a build, the service worker is assembled at runtime (this works
on a read-only disk too, just more slowly).

`pwa:icons` belongs in development (commit its output to `public/pwa/icons/`), not in your deployment.

## HTTPS

Service workers require HTTPS (except on `localhost`). Behind a proxy, configure trusted proxies so
that `APP_URL` and generated URLs use `https`.
