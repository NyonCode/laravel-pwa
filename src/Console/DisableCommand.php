<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use NyonCode\LaravelPwa\Pwa;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pwa:disable', description: 'Nouzově vypne service worker na všech zařízeních')]
class DisableCommand extends Command
{
    protected $signature = 'pwa:disable {--force : Bez potvrzení}';

    protected $description = 'Nouzově vypne service worker na všech zařízeních';

    public function handle(Pwa $pwa): int
    {
        if (config('pwa.sw.import') !== [] && config('pwa.sw.import') !== null) {
            $this->components->warn('Service worker načítá soubory ze sw.import (např. push systém). Odregistrace SW zruší i push odběry v prohlížečích.');
        }

        if (! $this->option('force') && ! $this->confirm('Opravdu vypnout service worker? Zařízení smažou cache a SW odregistrují při další návštěvě.', true)) {
            return self::FAILURE;
        }

        $pwa->disable();

        $this->components->info('Nouzové vypnutí aktivní. /sw.js teď vrací samodestrukční SW; vrátit: php artisan pwa:enable');

        if (! in_array(config('cache.default'), ['redis', 'database', 'memcached', 'dynamodb'], true) && config('pwa.sw.kill_switch_store') === null) {
            $this->components->warn('Cache store ['.config('cache.default').'] nemusí být sdílený mezi servery. Při více serverech nastavte PWA_KILL_SWITCH_STORE.');
        }

        return self::SUCCESS;
    }
}
