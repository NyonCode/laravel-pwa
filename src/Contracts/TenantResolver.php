<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Contracts;

use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Tenancy\Tenant;

/**
 * Tells the package which tenant a page belongs to, for tenants that share one domain under
 * different paths (/t/acme/, /t/globex/). Each tenant then gets its own installable app: its own
 * service worker scope, manifest id and start_url, and its own caches.
 *
 * Tenants on their own subdomain or domain need none of this: browsers already keep them apart.
 *
 * Register it in config('pwa.tenancy.resolver') or with Pwa::resolveTenantsUsing().
 */
interface TenantResolver
{
    /**
     * The tenant of a page request (route parameter, path, session …), or null outside any tenant.
     */
    public function current(Request $request): ?Tenant;

    /**
     * The tenant with this key, for requests that carry only the key (the manifest route).
     * Return null for unknown keys.
     */
    public function find(string $key): ?Tenant;
}
