---
title: Versioning
description: "Semantic Versioning from 1.0: what counts as the public API (PHP, config, Blade, JavaScript, the worker), what may change in a minor release, and the support policy."
---

# Versioning

From 1.0 the package follows [Semantic Versioning](https://semver.org): a minor release (1.x) adds
features and never breaks the public API, a patch release (1.x.y) only fixes bugs, and anything that
breaks the API waits for 2.0 with a guide in `UPGRADE.md`.

## Public API

| Area | Public |
| --- | --- |
| PHP | the facades `Pwa` and `WebPush`, `Pwa`, `ManifestBuilder`, `ServiceWorkerBuilder`, `CacheRule`, `CacheStrategy`, `DisplayStrategy`, `ManifestValidator`, everything in `Contracts`, `Events`, `Testing` and `Push` (except `MinishlinkTransport`, `SendWebPush` and `Vapid`), `Tenancy\Tenant`, the middleware `CachePage`, `PwaWebMiddleware`, `RememberStandalone` and their aliases |
| Configuration | every key in `config/pwa.php` and its meaning; new keys get defaults that keep the old behaviour |
| Artisan | command names, arguments and options (`pwa:*`) |
| HTTP | the routes under `pwa.route_prefix`, the request and response headers (`X-PWA-*`) |
| Blade | the components `<x-pwa::…>`, their props and slots, the `data-pwa-*` attributes, the `pwa-*` CSS classes and `--pwa-*` variables |
| Browser | `window.Pwa`, the `pwa:*` events and their `detail` |
| Service worker | `self.PwaWorker` for `sw.extend`, the messages it accepts, the cache names (`pwa-static-*`, `pwa-pages-*`, `pwa-rule-*`, `pwa-shared-*`, `pwa-meta`) |
| Translations | the keys in `pwa::messages` |

Classes and methods marked `@internal` (controllers, listeners, console command classes, icon drivers,
helpers in `Support`) and anything `protected` in them can change in any release. Published views are
yours: a release may change the originals, so compare them after an update.

## What a minor release may do

- add config keys, methods, events, components, client members and worker messages;
- change the service worker's caching internals, as long as the cache names and rules above hold
  (a new worker version activates on its own, see [Service worker](service-worker.md#versioning));
- raise the minimum version of an optional dependency (`minishlink/web-push`) in step with its own
  support;
- add support for a new Laravel or PHP version.

Dropping a Laravel or PHP version that is no longer supported upstream happens only in a minor
release, is announced in the changelog one release before, and never in a patch release.

## Support policy

| Version | PHP | Laravel | Bug fixes | Security fixes |
| --- | --- | --- | --- | --- |
| 1.x | 8.2 – 8.5 | 12, 13 | latest minor | latest minor |

Report vulnerabilities privately as described in [SECURITY.md](https://github.com/NyonCode/laravel-pwa/blob/main/SECURITY.md).
