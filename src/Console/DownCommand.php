<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use NyonCode\LaravelPwa\Support\Value;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'pwa:down', description: 'Put the application into maintenance mode with the PWA screen (wraps artisan down)')]
class DownCommand extends Command
{
    protected $signature = 'pwa:down
        {--message= : The message on the maintenance screen}
        {--until= : The expected end, e.g. "15:30" or "2026-11-21 08:00"}
        {--retry= : Seconds for the Retry-After header and the check interval}
        {--refresh= : The Refresh header in seconds}
        {--secret= : The secret phrase that bypasses maintenance mode}
        {--with-secret : Generate a secret phrase}';

    protected $description = 'Put the application into maintenance mode with the PWA screen (wraps artisan down)';

    public function handle(): int
    {
        $until = null;

        if (is_string($this->option('until')) && $this->option('until') !== '') {
            try {
                $until = Carbon::parse($this->option('until'));
            } catch (Throwable) {
                $this->components->error('Invalid time in --until.');

                return self::FAILURE;
            }
        }

        $retry = $this->option('retry');

        $retry ??= $until instanceof Carbon
            ? max(15, min(300, (int) now()->diffInSeconds($until, false)))
            : Value::configInt('pwa.maintenance.retry', 60);

        // The template is prerendered in this process, so changing the config is enough.
        config([
            'pwa.maintenance.message' => $this->option('message') ?: config('pwa.maintenance.message'),
            'pwa.maintenance.until' => $until?->toIso8601String(),
            'pwa.maintenance.retry' => (int) $retry,
        ]);

        $arguments = array_filter([
            '--render' => 'pwa::maintenance',
            '--retry' => (int) $retry,
            '--refresh' => $this->option('refresh') ?? config('pwa.maintenance.refresh'),
            '--secret' => $this->option('secret'),
            '--with-secret' => $this->option('with-secret') ?: null,
        ], static fn (mixed $value): bool => ! in_array($value, [null, false, ''], true));

        return $this->call('down', $arguments);
    }
}
