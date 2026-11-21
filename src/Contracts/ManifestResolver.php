<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Contracts;

use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;

/**
 * Adjusts the web app manifest per request (tenant, locale, branding …).
 *
 * Runs on the manifest route, which has no session or cookies. If the resolver needs to know
 * something about the page (the tenant, the locale), implement ManifestParameters as well: the
 * parameters are appended to the manifest link and arrive here in the query string. They are
 * user input, so validate them.
 *
 *     final class TenantManifest implements ManifestResolver
 *     {
 *         public function resolve(Request $request, ManifestBuilder $manifest): void
 *         {
 *             $tenant = Tenant::whereSlug($request->query('tenant'))->first();
 *
 *             $tenant && $manifest->name($tenant->name)->themeColor($tenant->color)->private();
 *         }
 *     }
 */
interface ManifestResolver
{
    public function resolve(Request $request, ManifestBuilder $manifest): void;
}
