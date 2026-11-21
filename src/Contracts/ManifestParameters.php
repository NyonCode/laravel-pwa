<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Contracts;

use Illuminate\Http\Request;

/**
 * Query parameters a ManifestResolver needs on the manifest route.
 *
 * Called while rendering <x-pwa::head/> (a normal page request with session and auth); the result
 * is appended to the manifest URL. Keep values short and non-secret: the URL is public and cached.
 */
interface ManifestParameters
{
    /**
     * @return array<string, string>
     */
    public function parameters(Request $page): array;
}
