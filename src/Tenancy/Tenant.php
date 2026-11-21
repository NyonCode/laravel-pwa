<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Tenancy;

use InvalidArgumentException;

/**
 * One installable app on a shared domain: its key, path scope and identity in the manifest.
 */
final readonly class Tenant
{
    public string $startUrl;

    /**
     * @param  string  $key  Short public identifier, [A-Za-z0-9_-]{1,64} (sent in the manifest URL).
     * @param  string  $scope  Path the tenant's pages live under, with leading and trailing slash, e.g. "/t/acme/".
     * @param  string|null  $startUrl  Defaults to the scope.
     * @param  string|null  $name  Manifest name (and short_name unless $shortName is given).
     */
    public function __construct(
        public string $key,
        public string $scope,
        ?string $startUrl = null,
        public ?string $name = null,
        public ?string $shortName = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid tenant key [{$key}], expected 1–64 letters, digits, dashes or underscores.");
        }

        if (! str_starts_with($scope, '/') || ! str_ends_with($scope, '/') || str_contains($scope, '//') || str_contains($scope, '..')) {
            throw new InvalidArgumentException("Invalid tenant scope [{$scope}], expected a path like /t/acme/.");
        }

        $startUrl ??= $scope;

        if (! str_starts_with((string) parse_url($startUrl, PHP_URL_PATH), $scope)) {
            throw new InvalidArgumentException("The start URL [{$startUrl}] of tenant [{$key}] is outside its scope [{$scope}].");
        }

        $this->startUrl = $startUrl;
    }
}
