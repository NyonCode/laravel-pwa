<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;

/**
 * Dispatched for every manifest request after the resolvers ran. Listeners may change the
 * manifest (it is the per-request copy), e.g. `$event->manifest->themeColor('#000')`.
 */
final readonly class ManifestResolving
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public ManifestBuilder $manifest,
    ) {}
}
