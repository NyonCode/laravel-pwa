<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\ServiceWorker;

/**
 * How the service worker answers a GET request matched by a CacheRule.
 */
enum CacheStrategy: string
{
    /** Network first; the cached copy only when offline, slow (timeout) or in maintenance (503). */
    case NetworkFirst = 'network-first';

    /** Cache first; the network only on a miss or once the copy is older than max_age. */
    case CacheFirst = 'cache-first';

    /** Cached copy immediately, refreshed in the background for the next request. */
    case StaleWhileRevalidate = 'stale-while-revalidate';

    /** Always the network, never stored. Useful to exclude paths from broader rules. */
    case NetworkOnly = 'network-only';

    /** Only the cache (e.g. precached files); 504 when nothing is stored. */
    case CacheOnly = 'cache-only';

    public function stores(): bool
    {
        return $this !== self::NetworkOnly;
    }
}
