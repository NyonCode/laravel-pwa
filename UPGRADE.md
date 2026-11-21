# Upgrade guide

Before 1.0 any minor version may change the cache or service worker format. On every update:

1. `composer update nyoncode/laravel-pwa`
2. Compare your `config/pwa.php` with `vendor/nyoncode/laravel-pwa/config/pwa.php`.
3. `npm run build && php artisan pwa:build`
4. `php artisan pwa:doctor`

The service worker migrates caches itself in its `activate` event (it deletes old ones).

## From 0.3 to 0.4

- **Nothing changes unless you enable push.** The new `push` config section is off by default and
  no migration runs by itself.
- **To use web push:** `composer require minishlink/web-push`, `php artisan pwa:push:vapid`,
  `php artisan vendor:publish --tag="pwa::migrations" && php artisan migrate`, set
  `PWA_PUSH_ENABLED=true` and `PWA_VAPID_SUBJECT`, add `HasPushSubscriptions` to your user model and
  run `php artisan pwa:build`. See the [push docs](https://nyoncode.github.io/laravel-pwa/push.html).
- **Coming from a separate push package** (`laravel-notification-channels/webpush`): its
  subscriptions cannot be moved, because they are bound to that package's VAPID key unless you reuse
  the same keys in `PWA_VAPID_PUBLIC_KEY` / `PWA_VAPID_PRIVATE_KEY`. Remove its worker listeners from
  `sw.import` once the built-in ones are on, or keep it and set `push.handle_in_worker = false`.

## From 0.2 to 0.3

- **Run `php artisan pwa:icons` again** to get `monochrome-512.png` (listed in the manifest once it
  exists). Add `--splash` for iOS startup images; they are linked by `<x-pwa::head/>` automatically.
- **New config keys** (all optional): `icons.splash`, `offline.warm`, `tenancy.resolver`. Without
  them nothing changes.
- **Error message:** an invalid colour now reads `Invalid colour [red], expected #rgb or #rrggbb.`
  (it was `Invalid background colour […]`). Only matters if you match the text.
- **Cache names stay the same** for the worker of `pwa.scope`. Only path tenants (new in 0.3) get
  namespaced names.

## From 0.1 to 0.2

- **Cache names changed.** API and image data now live in rule caches: `pwa-api-{user}` became
  `pwa-rule-api-{user}` and `pwa-images-{user}` became `pwa-rule-images-{user}`. The new worker
  deletes the old caches on activation, so users refetch that data once. Nothing to do in your app.
- **New config keys** (all optional): `cache.rules`, `cache.images.enabled`, `sw.extend`.
  `cache.api` keeps working as a shorthand for network-first rules.
- **`cache.images.max_age` is now enforced.** It was ignored in 0.1. Images older than the value
  (30 days by default) are refetched; offline the old copy is still served.
- **Invalid cache config now fails loudly.** `pwa:build` fails and `pwa:doctor` reports an error
  for unknown rule keys or strategies, or for non-string entries in `sw.import` / `sw.extend` /
  `cache.api` / `cache.never_cache`.
- **Config values are type-checked.** Numeric and boolean keys (for example
  `session.keepalive_minutes`, `install.min_visits`, `desktop_nav.enabled`) and string keys (`scope`,
  `start_url`, `sw.url`, `route_prefix` …) must have their natural type. A quoted number such as
  `'10'` now throws an `InvalidArgumentException` naming the key instead of being cast silently.
  `pwa.enabled` still accepts env strings like `"1"` and `"0"`.
- If DevTools code or tests of yours look for the cache names `pwa-api-*` or `pwa-images-*`,
  switch them to `pwa-rule-*`.
