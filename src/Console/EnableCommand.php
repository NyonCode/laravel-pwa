<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use NyonCode\LaravelPwa\Pwa;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pwa:enable', description: 'Zruší nouzové vypnutí service workeru')]
class EnableCommand extends Command
{
    protected $signature = 'pwa:enable';

    protected $description = 'Zruší nouzové vypnutí service workeru';

    public function handle(Pwa $pwa): int
    {
        $pwa->enable();
        $this->components->info('Service worker je znovu zapnutý. Zařízení ho zaregistrují při další návštěvě.');

        return self::SUCCESS;
    }
}
