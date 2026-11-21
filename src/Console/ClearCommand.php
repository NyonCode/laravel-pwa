<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pwa:clear', description: 'Delete the built service worker (it is then built at runtime)')]
class ClearCommand extends Command
{
    protected $signature = 'pwa:clear';

    protected $description = 'Delete the built service worker (it is then built at runtime)';

    public function handle(ServiceWorkerBuilder $builder): int
    {
        $builder->clear();
        $this->components->info('Built service worker deleted.');

        return self::SUCCESS;
    }
}
