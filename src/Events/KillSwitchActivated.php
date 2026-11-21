<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by `pwa:disable` / Pwa::disable(): devices unregister the worker on their next visit.
 */
final class KillSwitchActivated
{
    use Dispatchable;
}
