<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'pwa:down', description: 'Režim údržby s obrazovkou PWA (wrapper nad artisan down)')]
class DownCommand extends Command
{
    protected $signature = 'pwa:down
        {--message= : Zpráva na obrazovce údržby}
        {--until= : Očekávaný konec, např. "15:30" nebo "2026-11-21 08:00"}
        {--retry= : Sekundy pro hlavičku Retry-After a interval kontrol}
        {--refresh= : Hlavička Refresh v sekundách}
        {--secret= : Tajná fráze pro obejití údržby}
        {--with-secret : Vygenerovat tajnou frázi}';

    protected $description = 'Režim údržby s obrazovkou PWA (wrapper nad artisan down)';

    public function handle(): int
    {
        $until = null;

        if (is_string($this->option('until')) && $this->option('until') !== '') {
            try {
                $until = Carbon::parse($this->option('until'));
            } catch (Throwable) {
                $this->components->error('Neplatný čas v --until.');

                return self::FAILURE;
            }
        }

        $retry = $this->option('retry');

        if ($retry === null) {
            $retry = $until !== null
                ? max(15, min(300, (int) now()->diffInSeconds($until, false)))
                : (int) config('pwa.maintenance.retry', 60);
        }

        // Šablona se předrenderuje v tomto procesu, proto stačí upravit config.
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
        ], static fn (mixed $value): bool => $value !== null && $value !== false && $value !== '');

        return $this->call('down', $arguments);
    }
}
