<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use NyonCode\LaravelPwa\Pwa;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'pwa:doctor', description: 'Zkontroluje nastavení PWA (manifest, ikony, service worker, údržba, session)')]
class DoctorCommand extends Command
{
    protected $signature = 'pwa:doctor {--ci : Vrátit chybový kód při chybách (do deploy skriptu)}';

    protected $description = 'Zkontroluje nastavení PWA (manifest, ikony, service worker, údržba, session)';

    /** @var list<array{level: string, message: string}> */
    protected array $results = [];

    public function handle(Pwa $pwa, ServiceWorkerBuilder $builder): int
    {
        $this->checkGeneral($pwa);
        $this->checkManifest($pwa);
        $this->checkServiceWorker($builder);
        $this->checkMaintenance();
        $this->checkSession();

        foreach ($this->results as $result) {
            match ($result['level']) {
                'ok' => $this->components->twoColumnDetail($result['message'], '<fg=green;options=bold>OK</>'),
                'warn' => $this->components->twoColumnDetail($result['message'], '<fg=yellow;options=bold>VAROVÁNÍ</>'),
                default => $this->components->twoColumnDetail($result['message'], '<fg=red;options=bold>CHYBA</>'),
            };
        }

        $errors = count(array_filter($this->results, fn (array $r): bool => $r['level'] === 'error'));
        $warnings = count(array_filter($this->results, fn (array $r): bool => $r['level'] === 'warn'));

        $this->newLine();
        $errors > 0
            ? $this->components->error("Chyby: {$errors}, varování: {$warnings}")
            : $this->components->info("Bez chyb, varování: {$warnings}");

        return $errors > 0 && $this->option('ci') ? self::FAILURE : self::SUCCESS;
    }

    protected function checkGeneral(Pwa $pwa): void
    {
        $url = (string) config('app.url');
        $this->add(str_starts_with($url, 'https://') || app()->isLocal() ? 'ok' : 'error', "HTTPS (APP_URL = {$url})");
        $this->add(config('pwa.enabled') ? 'ok' : 'warn', 'Service worker zapnutý v configu (pwa.enabled)');
        $this->add($pwa->killed() ? 'error' : 'ok', 'Nouzové vypnutí neaktivní (pwa:enable)');
    }

    protected function checkManifest(Pwa $pwa): void
    {
        try {
            $manifest = $pwa->manifest()->toArray();
        } catch (Throwable $e) {
            $this->add('error', 'Manifest: '.$e->getMessage());

            return;
        }

        $this->add(($manifest['name'] ?? '') !== '' ? 'ok' : 'error', 'Manifest: name');

        $sizes = array_column($manifest['icons'] ?? [], 'sizes');
        $this->add(in_array('192x192', $sizes, true) ? 'ok' : 'error', 'Ikona 192×192 (php artisan pwa:icons)');
        $this->add(in_array('512x512', $sizes, true) ? 'ok' : 'error', 'Ikona 512×512 (php artisan pwa:icons)');
        $this->add(in_array('maskable', array_column($manifest['icons'] ?? [], 'purpose'), true) ? 'ok' : 'warn', 'Maskable ikona pro Android');

        $iconPath = trim((string) config('pwa.icons.path', 'pwa/icons'), '/');
        $this->add(File::exists(public_path($iconPath.'/apple-touch-icon.png')) ? 'ok' : 'warn', 'apple-touch-icon pro iPhone a iPad');

        $scope = (string) ($manifest['scope'] ?? '/');
        $start = (string) parse_url((string) ($manifest['start_url'] ?? '/'), PHP_URL_PATH);
        $this->add(str_starts_with($start ?: '/', $scope) ? 'ok' : 'error', 'start_url leží uvnitř scope');
    }

    protected function checkServiceWorker(ServiceWorkerBuilder $builder): void
    {
        $swPath = ltrim((string) config('pwa.sw.url', '/sw.js'), '/');
        $this->add(
            File::exists(public_path($swPath)) ? 'error' : 'ok',
            "public/{$swPath} neexistuje (jinak ho webserver vrací i po pwa:disable)",
        );

        $this->add(
            File::exists(public_path((string) config('pwa.sw.vite_manifest', 'build/manifest.json'))) ? 'ok' : 'warn',
            'Vite manifest pro precache (npm run build)',
        );

        if (! $builder->isCompiled()) {
            $this->add('warn', 'Service worker sestavený (php artisan pwa:build)');
        } else {
            $fresh = (string) ($builder->runtimeConfig()['version'] ?? '');
            $built = $builder->versionOf((string) File::get($builder->compiledPath()));
            $this->add($fresh === $built ? 'ok' : 'error', "Sestavený SW je aktuální ({$built})");
        }

        foreach ($builder->imports() as $import) {
            if (parse_url($import, PHP_URL_HOST) !== null) {
                $this->add('warn', "sw.import {$import} je z jiné domény");

                continue;
            }

            $this->add(File::exists(public_path(ltrim($import, '/'))) ? 'ok' : 'error', "sw.import {$import} existuje");
        }
    }

    protected function checkMaintenance(): void
    {
        try {
            $except = app(PreventRequestsDuringMaintenance::class)->getExcludedPaths();
        } catch (Throwable) {
            $except = [];
        }

        $needed = ['manifest.webmanifest', ltrim((string) config('pwa.sw.url', '/sw.js'), '/'), trim((string) config('pwa.route_prefix', 'pwa'), '/').'/*'];
        $missing = array_values(array_diff($needed, array_map(fn ($p) => trim((string) $p, '/'), $except)));

        $this->add(
            $missing === [] ? 'ok' : 'warn',
            $missing === [] ? 'Výjimky z údržby' : 'Výjimky z údržby chybí: '.implode(', ', $missing).' (bootstrap/app.php)',
        );
    }

    protected function checkSession(): void
    {
        $this->add(config('session.driver') === 'array' ? 'error' : 'ok', 'Session driver ukládá data ('.config('session.driver').')');

        $hasRemember = collect(RouteFacade::getRoutes()->getRoutes())
            ->contains(fn (Route $route): bool => in_array('pwa.remember', $route->gatherMiddleware(), true));
        $this->add($hasRemember ? 'ok' : 'warn', 'Middleware pwa.remember na přihlašovací routě');

        $hasCache = collect(RouteFacade::getRoutes()->getRoutes())
            ->contains(fn (Route $route): bool => in_array('pwa.cache', $route->gatherMiddleware(), true));
        $this->add($hasCache ? 'ok' : 'warn', 'Některé stránky povolené pro offline (middleware pwa.cache)');

        $store = config('pwa.sw.kill_switch_store') ?? config('cache.default');
        $this->add(in_array($store, ['file', 'array'], true) ? 'warn' : 'ok', "Úložiště nouzového vypnutí sdílené mezi servery ({$store})");
    }

    protected function add(string $level, string $message): void
    {
        $this->results[] = ['level' => $level, 'message' => $message];
    }
}
