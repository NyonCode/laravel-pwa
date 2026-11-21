<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Tenancy;

use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Contracts\ManifestParameters;
use NyonCode\LaravelPwa\Contracts\ManifestResolver;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;
use NyonCode\LaravelPwa\Pwa;

/**
 * Gives each path tenant its own manifest identity (id, scope, start_url, name).
 *
 * Registered automatically, before every other resolver, when a TenantResolver is configured.
 * The page adds ?tenant={key}; the manifest route looks the key up with TenantResolver::find().
 */
final class TenantManifestResolver implements ManifestParameters, ManifestResolver
{
    public const PARAMETER = 'tenant';

    public function __construct(protected Pwa $pwa) {}

    public function parameters(Request $page): array
    {
        $tenant = $this->pwa->tenant($page);

        return $tenant instanceof Tenant ? [self::PARAMETER => $tenant->key] : [];
    }

    public function resolve(Request $request, ManifestBuilder $manifest): void
    {
        $tenant = $this->pwa->tenant($request);

        if (! $tenant instanceof Tenant) {
            return;
        }

        // id must differ per tenant, otherwise the browser treats every tenant as one installed app.
        $manifest->id($tenant->scope)->scope($tenant->scope)->startUrl($tenant->startUrl);

        if ($tenant->name !== null) {
            $manifest->name($tenant->name)->shortName($tenant->shortName ?? $tenant->name);
        } elseif ($tenant->shortName !== null) {
            $manifest->shortName($tenant->shortName);
        }
    }
}
