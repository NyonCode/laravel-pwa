<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use NyonCode\LaravelPwa\Pwa;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pwa:disable', description: 'Disable the service worker on every device (kill switch)')]
class DisableCommand extends Command
{
    protected $signature = 'pwa:disable {--force : Skip the confirmation}';

    protected $description = 'Disable the service worker on every device (kill switch)';

    public function handle(Pwa $pwa): int
    {
        if (config('pwa.sw.import') !== [] && config('pwa.sw.import') !== null) {
            $this->components->warn('The service worker loads files from sw.import (e.g. a push system). Unregistering the SW also removes the push subscriptions in browsers.');
        }

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to disable the service worker? Devices will clear the cache and unregister the SW on their next visit.', true)) {
            return self::FAILURE;
        }

        $pwa->disable();

        $this->components->info('Kill switch active. /sw.js now returns a self-destroying SW; undo with php artisan pwa:enable');

        if (! in_array(config('cache.default'), ['redis', 'database', 'memcached', 'dynamodb'], true) && config('pwa.sw.kill_switch_store') === null) {
            $this->components->warn('Cache store ['.config('cache.default').'] may not be shared between servers. With multiple servers, set PWA_KILL_SWITCH_STORE.');
        }

        return self::SUCCESS;
    }
}
