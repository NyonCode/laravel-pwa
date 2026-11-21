<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Manifest;

use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Contracts\ManifestParameters;
use NyonCode\LaravelPwa\Contracts\ManifestResolver;

/**
 * Builds the manifest in the page's locale (`?lang=`), limited to `pwa.locales` (list the default
 * locale there too). Other values are ignored and the manifest uses the default locale.
 *
 * Registered automatically when `pwa.locales` is not empty. The manifest route has no session,
 * so without the parameter every user would get the default locale.
 */
final class LocaleResolver implements ManifestParameters, ManifestResolver
{
    public const PARAMETER = 'lang';

    public function parameters(Request $page): array
    {
        // Always explicit: app()->setLocale() also rewrites config('app.locale'), so the page
        // cannot tell the default locale from the current one.
        $locale = $this->allowed(app()->getLocale());

        return $locale !== null ? [self::PARAMETER => str_replace('_', '-', $locale)] : [];
    }

    public function resolve(Request $request, ManifestBuilder $manifest): void
    {
        $requested = $request->query(self::PARAMETER);
        $locale = is_string($requested) ? $this->allowed($requested) : null;

        if ($locale !== null) {
            $manifest->locale($locale);
        }
    }

    /**
     * The configured spelling of an allowed locale (`cs-CZ` and `cs_CZ` are the same), or null.
     */
    public function allowed(string $locale): ?string
    {
        $normalise = static fn (string $value): string => strtolower(str_replace('_', '-', $value));
        $wanted = $normalise($locale);

        // Not config('app.locale'): app()->setLocale() rewrites it on every localised request.
        foreach ($this->locales() as $candidate) {
            if ($normalise($candidate) === $wanted) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function locales(): array
    {
        return array_values(array_filter(config()->array('pwa.locales', []), is_string(...)));
    }
}
