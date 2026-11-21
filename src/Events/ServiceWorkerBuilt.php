<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after `pwa:build` (ServiceWorkerBuilder::write) wrote the service worker.
 * Use it to purge a CDN, notify a deploy channel or record the version.
 */
final readonly class ServiceWorkerBuilt
{
    use Dispatchable;

    /**
     * @param  list<string>  $precache
     */
    public function __construct(
        public string $version,
        public string $path,
        public array $precache,
    ) {}
}
