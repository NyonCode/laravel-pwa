<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Listeners;

use Illuminate\Support\Facades\Log;
use NyonCode\LaravelPwa\Events\TelemetryReceived;

/**
 * Writes worker and client errors to the log (`pwa.telemetry.channel`, else the default channel).
 * Turn off with `pwa.telemetry.log = false` when you handle TelemetryReceived yourself.
 *
 * @internal
 */
final class LogTelemetry
{
    public function handle(TelemetryReceived $event): void
    {
        if (! config()->boolean('pwa.telemetry.log', true)) {
            return;
        }

        $channel = config('pwa.telemetry.channel');
        $logger = is_string($channel) && config("logging.channels.{$channel}") !== null
            ? Log::channel($channel)
            : logger();

        foreach ($event->events as $entry) {
            $logger->warning('[pwa] '.$entry['type'].': '.$entry['message'], [
                'pwa' => true,
                'source' => $event->source,
                'version' => $entry['version'] ?? null,
                'display' => $entry['display'] ?? null,
                'stack' => $entry['stack'] ?? null,
                'user_agent' => $event->userAgent,
            ]);
        }
    }
}
