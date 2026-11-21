<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by `pwa:enable` / Pwa::enable(): the normal worker is served again.
 */
final class KillSwitchDeactivated
{
    use Dispatchable;
}
