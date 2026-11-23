---
title: Roadmap
description: "What is planned after 1.1: Livewire navigation offline, an outbox for failed replays, a doctor for the live site, supported versions in 2027 and 2.0."
---

# Roadmap

Plans, not promises: the order and the content can change. Everything in 1.x keeps the public API (see
[Versioning](versioning.md)); a release that breaks it waits for 2.0. Ideas and requests are welcome in
[GitHub issues](https://github.com/NyonCode/laravel-pwa/issues).

## Released

| Version | Highlights |
| --- | --- |
| 1.0 | stable public API, UI components, WebKit in e2e, Vite precache, push in batches and statistics |
| 1.0.1–1.0.3 | fixes from a real app and the starter kits, starter kit CI, the `unavailable` push state |
| 1.1 | Inertia links to stored pages work offline, `pwa:push:prune` |

## 1.2: Livewire navigation and the outbox

- **`wire:navigate` offline.** Livewire fetches the next page with `X-Livewire-Navigate` instead of
  navigating, so the worker does not answer it from the stored pages today, the same gap Inertia had
  before 1.1. The worker will serve stored pages to these requests too, with a step in the Livewire
  starter kit smoke test.
- **Outbox for failed replays.** A request sent from the offline queue that the server refuses (a 422
  validation error, a 403, a 409) is now dropped after the `replayed` event. It will stay in an outbox
  instead: `Pwa.outbox()` lists it with the status and the server's answer, `retry()` and `discard()`
  act on it, a new `replay-failed` event and an `<x-pwa::outbox>` component show it to the user.
- **Queue limits.** Optional maximum age and count of queued requests (`offline.queue_max_age`,
  `offline.queue_max`), so that an old request is not sent days later without the user knowing.

## 1.3: The live site

- **`pwa:doctor --url=`.** Checks the deployed site from outside: HTTPS, the manifest and its icons, the
  worker's headers and scope (`Service-Worker-Allowed`, caching headers behind a CDN), the `X-PWA-*`
  headers of a cached page and the maintenance answer. Useful in a deployment pipeline.

## Supported versions in 2027

PHP 8.2 stops receiving security fixes at the end of 2026 and Laravel 12 in February 2027. Following the
[support policy](versioning.md#support-policy), the first minor release after that drops them (PHP 8.3+,
Laravel 13+), announced in the changelog one release before. Laravel 14 is supported as soon as it is
released.

## 2.0

No date yet: 2.0 comes only when a change cannot be made without breaking the public API. Candidates
collected until then: removing everything deprecated in 1.x and renaming config keys that grew
inconsistent, each with a guide in `UPGRADE.md`.

## Ongoing

- The [starter kit CI](testing.md) runs every week against the latest Laravel starter kits, so that a
  change upstream shows up before users report it.
- New browser features (Declarative Web Push, manifest members) are added in minor releases once at
  least two engines ship them or one platform needs them for installation.
