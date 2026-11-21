<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'pwa:build', description: 'Sestaví service worker z Vite buildu (patří do deploy skriptu za npm run build)')]
class BuildCommand extends Command
{
    protected $signature = 'pwa:build';

    protected $description = 'Sestaví service worker z Vite buildu (patří do deploy skriptu za npm run build)';

    public function handle(ServiceWorkerBuilder $builder): int
    {
        try {
            $files = $builder->viteFiles();
            $version = $builder->write();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($files === []) {
            $this->components->warn('Vite manifest nenalezen nebo bez vstupů: precache obsahuje jen offline stránku. Spusťte nejdřív npm run build.');
        }

        $this->components->info(sprintf(
            'Service worker %s sestaven (%d souborů v precache) → %s',
            $version,
            count($builder->precache()),
            str_replace(base_path().'/', '', $builder->compiledPath()),
        ));

        return self::SUCCESS;
    }
}
