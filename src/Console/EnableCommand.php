<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use NyonCode\LaravelPwa\Pwa;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pwa:enable', description: 'Deactivate the service worker kill switch')]
class EnableCommand extends Command
{
    protected $signature = 'pwa:enable';

    protected $description = 'Deactivate the service worker kill switch';

    public function handle(Pwa $pwa): int
    {
        $pwa->enable();
        $this->components->info('Service worker enabled again. Devices will register it on their next visit.');

        return self::SUCCESS;
    }
}
