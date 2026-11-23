# Upgrade guide

The package follows [Semantic Versioning](https://nyoncode.github.io/laravel-pwa/versioning.html):
updates within 1.x never break the public API. On every update:

1. `composer update nyoncode/laravel-pwa`
2. Compare your `config/pwa.php` with `vendor/nyoncode/laravel-pwa/config/pwa.php` (new keys are
   optional and default to the previous behaviour).
3. If you published the views, compare them with the package's.
4. `npm run build && php artisan pwa:build`
5. `php artisan pwa:doctor`

The service worker migrates its caches itself when the new version activates.

## From 1.1 to 1.2

Nothing to change. To use the new pieces:

- **Outbox:** queued requests the server refuses on replay are kept for the user. A published
  `config/pwa.php` has no `outbox` key in its `offline` section and keeps dropping them, as before; add
  `'outbox' => true` (and optionally `'queue_max'`, `'queue_max_age'`) to turn it on, then place
  `<x-pwa::outbox/>` where the user sees it. Without the component the client shows a bar.
- **`wire:navigate` offline** needs nothing: after `pwa:build`, links to pages with `pwa.cache` work
  offline in Livewire apps.

## From 1.0 to 1.1

Nothing to change. To use the new pieces:

- **Pruning push subscriptions:** schedule `php artisan pwa:push:prune` daily. To also delete
  subscriptions unused for 90 days (not only expired ones), add `'prune_after_days' => 90` to the `push`
  section of a published `config/pwa.php`; without the key, `pwa:push:prune` and `model:prune` delete
  expired subscriptions only, as before.
- **Inertia offline navigation** needs nothing: after `pwa:build`, links to pages with `pwa.cache` work
  offline.

## From a pre-release (0.x) to 1.0

Versions before 1.0 are no longer available. If an app still runs one, update the constraint to
`"nyoncode/laravel-pwa": "^1.0"` and check the points below; each one names the version it changed in.

**Service worker and caches**

- The worker deletes caches of older versions on activation (`pwa-api-*`, `pwa-images-*` from 0.1);
  users refetch that data once. DevTools code or tests that look for those names should use
  `pwa-rule-*`.
- The precache now holds the client script and, from the Vite build, every chunk an entry imports
  statically with its CSS and assets (`sw.precache_vite = 'imports'`). Set it to `'entries'` for the
  0.x behaviour, or leave large files out with `sw.precache_exclude`. `pwa:build` prints the size.
- `cache.images.max_age` is enforced (it was ignored in 0.1).

**Configuration**

- Values are type-checked (0.2): a quoted number such as `'10'` throws an `InvalidArgumentException`
  naming the key. `pwa.enabled` still accepts env strings like `"1"`.
- Invalid cache rules and non-string entries in `sw.import`, `sw.extend`, `cache.api` or
  `cache.never_cache` fail `pwa:build` and show up in `pwa:doctor` (0.2).

**Blade components and the client**

- `<x-pwa::install/>` shows step-by-step iPhone instructions (`ios="sheet"`) and prints small base
  styles. `ios="hint"` gives the one-line hint of 0.x, `:styles="false"` drops the styles.
- A custom update UI must call `Pwa.applyUpdate()` instead of posting `SKIP_WAITING`; otherwise the
  page never reloads after the update. With `<x-pwa::update-toast/>` or `updates.bar = false`, the
  built-in bar is not shown.
- Run `php artisan pwa:icons` again for `monochrome-512.png`, and with `--splash` for iOS startup
  images (0.3).

**Web push** (if you enabled it in 0.4)

- Nothing to migrate. `PushSent` is now dispatched after every send, and `WebPush::queue()` sends to
  many recipients in background jobs.

**PHP API**

- Controllers, listeners, console command classes, icon drivers and the helpers listed on the
  [Versioning](https://nyoncode.github.io/laravel-pwa/versioning.html) page are `@internal`. Do not
  extend or call them directly; use the facades, contracts and events.
