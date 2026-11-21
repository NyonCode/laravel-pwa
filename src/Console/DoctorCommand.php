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

#[AsCommand(name: 'pwa:doctor', description: 'Check the PWA setup (manifest, icons, service worker, maintenance, session)')]
class DoctorCommand extends Command
{
    protected $signature = 'pwa:doctor {--ci : Return a failure exit code on errors (for deploy scripts)}';

    protected $description = 'Check the PWA setup (manifest, icons, service worker, maintenance, session)';

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
                'warn' => $this->components->twoColumnDetail($result['message'], '<fg=yellow;options=bold>WARN</>'),
                default => $this->components->twoColumnDetail($result['message'], '<fg=red;options=bold>ERROR</>'),
            };
        }

        $errors = count(array_filter($this->results, fn (array $r): bool => $r['level'] === 'error'));
        $warnings = count(array_filter($this->results, fn (array $r): bool => $r['level'] === 'warn'));

        $this->newLine();
        $errors > 0
            ? $this->components->error("Errors: {$errors}, warnings: {$warnings}")
            : $this->components->info("No errors, warnings: {$warnings}");

        return $errors > 0 && $this->option('ci') ? self::FAILURE : self::SUCCESS;
    }

    protected function checkGeneral(Pwa $pwa): void
    {
        $url = (string) config('app.url');
        $this->add(str_starts_with($url, 'https://') || app()->isLocal() ? 'ok' : 'error', "HTTPS (APP_URL = {$url})");
        $this->add(config('pwa.enabled') ? 'ok' : 'warn', 'Service worker enabled in config (pwa.enabled)');
        $this->add($pwa->killed() ? 'error' : 'ok', 'Kill switch inactive (pwa:enable)');
    }

    protected function checkManifest(Pwa $pwa): void
    {
        try {
            // With the resolvers, as the manifest route builds it.
            $manifest = $pwa->manifest()->forRequest(request())->toArray();
        } catch (Throwable $e) {
            $this->add('error', 'Manifest: '.$e->getMessage());

            return;
        }

        $this->add(($manifest['name'] ?? '') !== '' ? 'ok' : 'error', 'Manifest: name');

        $sizes = array_column($manifest['icons'] ?? [], 'sizes');
        $this->add(in_array('192x192', $sizes, true) ? 'ok' : 'error', 'Icon 192×192 (php artisan pwa:icons)');
        $this->add(in_array('512x512', $sizes, true) ? 'ok' : 'error', 'Icon 512×512 (php artisan pwa:icons)');
        $this->add(in_array('maskable', array_column($manifest['icons'] ?? [], 'purpose'), true) ? 'ok' : 'warn', 'Maskable icon for Android');

        $iconPath = trim((string) config('pwa.icons.path', 'pwa/icons'), '/');
        $this->add(File::exists(public_path($iconPath.'/apple-touch-icon.png')) ? 'ok' : 'warn', 'apple-touch-icon for iPhone and iPad');

        $scope = (string) ($manifest['scope'] ?? '/');
        $start = (string) parse_url((string) ($manifest['start_url'] ?? '/'), PHP_URL_PATH);
        $this->add(str_starts_with($start ?: '/', $scope) ? 'ok' : 'error', 'start_url inside scope');
    }

    protected function checkServiceWorker(ServiceWorkerBuilder $builder): void
    {
        $swPath = ltrim((string) config('pwa.sw.url', '/sw.js'), '/');
        $this->add(
            File::exists(public_path($swPath)) ? 'error' : 'ok',
            "public/{$swPath} does not exist (otherwise the web server serves it even after pwa:disable)",
        );

        $this->add(
            File::exists(public_path((string) config('pwa.sw.vite_manifest', 'build/manifest.json'))) ? 'ok' : 'warn',
            'Vite manifest for precache (npm run build)',
        );

        if (! $this->checkCacheRules($builder)) {
            return;
        }

        if (! $builder->isCompiled()) {
            $this->add('warn', 'Service worker built (php artisan pwa:build)');
        } else {
            $fresh = (string) ($builder->runtimeConfig()['version'] ?? '');
            $built = $builder->versionOf((string) File::get($builder->compiledPath()));
            $this->add($fresh === $built ? 'ok' : 'error', "Built service worker is up to date ({$built})");
        }

        foreach (['sw.import' => $builder->imports(), 'sw.extend' => $builder->extensions()] as $key => $scripts) {
            foreach ($scripts as $script) {
                if (parse_url($script, PHP_URL_HOST) !== null) {
                    $this->add('warn', "{$key} {$script} is on another domain");

                    continue;
                }

                $this->add(File::exists(public_path(ltrim($script, '/'))) ? 'ok' : 'error', "{$key} {$script} exists");
            }
        }
    }

    /**
     * Valid rules, and no shared cache over paths the package never caches (login, Livewire …).
     */
    protected function checkCacheRules(ServiceWorkerBuilder $builder): bool
    {
        try {
            $rules = $builder->rules();
        } catch (Throwable $e) {
            $this->add('error', 'Cache rules: '.$e->getMessage());

            return false;
        }

        $this->add('ok', 'Cache rules valid ('.count($rules).')');

        $neverCache = array_map(
            static fn (mixed $path): string => str_replace('*', 'x', (string) $path),
            (array) config('pwa.cache.never_cache', []),
        );

        foreach ($rules as $rule) {
            $match = $rule->match();

            if (! $rule->isShared() || $match === null) {
                continue;
            }

            $pattern = '#^'.str_replace('\\*', '.*', preg_quote($match, '#')).'$#';
            $covered = array_values(array_filter($neverCache, static fn (string $path): bool => preg_match($pattern, $path) === 1));

            if ($covered !== []) {
                $this->add('warn', "Shared cache rule [{$rule->getName()}] also matches never-cached paths (".implode(', ', $covered).'); narrow its pattern');
            }
        }

        return true;
    }

    protected function checkMaintenance(): void
    {
        try {
            $except = app(PreventRequestsDuringMaintenance::class)->getExcludedPaths();
        } catch (Throwable) {
            $except = [];
        }

        $needed = ['manifest.webmanifest', ltrim((string) config('pwa.sw.url', '/sw.js'), '/'), trim((string) config('pwa.route_prefix', 'pwa'), '/').'/*'];
        $missing = array_values(array_diff($needed, array_map(fn ($p): string => trim((string) $p, '/'), $except)));

        $this->add(
            $missing === [] ? 'ok' : 'warn',
            $missing === [] ? 'Maintenance mode exceptions' : 'Maintenance mode exceptions missing: '.implode(', ', $missing).' (bootstrap/app.php)',
        );
    }

    protected function checkSession(): void
    {
        $this->add(config('session.driver') === 'array' ? 'error' : 'ok', 'Session driver persists data ('.config('session.driver').')');

        $hasRemember = collect(RouteFacade::getRoutes()->getRoutes())
            ->contains(fn (Route $route): bool => in_array('pwa.remember', $route->gatherMiddleware(), true));
        $this->add($hasRemember ? 'ok' : 'warn', 'pwa.remember middleware on the login route');

        $hasCache = collect(RouteFacade::getRoutes()->getRoutes())
            ->contains(fn (Route $route): bool => in_array('pwa.cache', $route->gatherMiddleware(), true));
        $this->add($hasCache ? 'ok' : 'warn', 'Some pages available offline (pwa.cache middleware)');

        $store = config('pwa.sw.kill_switch_store') ?? config('cache.default');
        $this->add(in_array($store, ['file', 'array'], true) ? 'warn' : 'ok', "Kill switch store shared between servers ({$store})");
    }

    protected function add(string $level, string $message): void
    {
        $this->results[] = ['level' => $level, 'message' => $message];
    }
}
