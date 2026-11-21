<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'pwa:build', description: 'Build the service worker from the Vite build (run it in your deploy script after npm run build)')]
class BuildCommand extends Command
{
    protected $signature = 'pwa:build';

    protected $description = 'Build the service worker from the Vite build (run it in your deploy script after npm run build)';

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
            $this->components->warn('Vite manifest not found or without entries: the precache holds only the offline page. Run npm run build first.');
        }

        $kilobytes = (int) ceil($builder->precacheBytes() / 1024);

        $this->components->info(sprintf(
            'Service worker %s built (%d files precached, %d KB) → %s',
            $version,
            count($builder->precache()),
            $kilobytes,
            str_replace(base_path().'/', '', $builder->compiledPath()),
        ));

        $limit = config()->integer('pwa.sw.precache_warn_kb', 5120);

        if ($limit > 0 && $kilobytes > $limit) {
            $this->components->warn("The precache is {$kilobytes} KB (pwa.sw.precache_warn_kb = {$limit}): every visitor downloads it on install. Exclude large files with pwa.sw.precache_exclude or use precache_vite = 'entries'.");
        }

        return self::SUCCESS;
    }
}
