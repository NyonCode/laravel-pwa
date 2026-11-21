<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pwa:clear', description: 'Smaže sestavený service worker (pak se sestavuje za běhu)')]
class ClearCommand extends Command
{
    protected $signature = 'pwa:clear';

    protected $description = 'Smaže sestavený service worker (pak se sestavuje za běhu)';

    public function handle(ServiceWorkerBuilder $builder): int
    {
        $builder->clear();
        $this->components->info('Sestavený service worker smazán.');

        return self::SUCCESS;
    }
}
