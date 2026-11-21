<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Errors reported by the service worker or the client (`POST /pwa/telemetry`), already validated.
 * The package logs them (listener LogTelemetry, `pwa.telemetry.log`); listen to send them to
 * Sentry, Flare or metrics instead. Contains technical data only, no personal data.
 */
final readonly class TelemetryReceived
{
    use Dispatchable;

    /**
     * @param  'sw'|'client'  $source
     * @param  list<array{type: string, message: string, stack?: string|null, version?: string|null, display?: string|null}>  $events
     */
    public function __construct(
        public string $source,
        public array $events,
        public ?string $userAgent,
    ) {}
}
