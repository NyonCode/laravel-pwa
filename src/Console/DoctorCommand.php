<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use NyonCode\LaravelPwa\Icons\SplashScreens;
use NyonCode\LaravelPwa\Pwa;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use NyonCode\LaravelPwa\Support\Value;
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
        $url = Value::string(config('app.url'));
        $this->add(str_starts_with($url, 'https://') || app()->isLocal() ? 'ok' : 'error', "HTTPS (APP_URL = {$url})");
        $this->add(config('pwa.enabled') ? 'ok' : 'warn', 'Service worker enabled in config (pwa.enabled)');
        $this->add($pwa->killed() ? 'error' : 'ok', 'Kill switch inactive (pwa:enable)');

        if (config('pwa.tenancy.resolver') !== null) {
            try {
                $this->add('ok', 'Tenant resolver '.((object) $pwa->tenancy())::class);
            } catch (Throwable $e) {
                $this->add('error', 'Tenant resolver: '.$e->getMessage());
            }
        }
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

        $icons = is_array($manifest['icons'] ?? null) ? $manifest['icons'] : [];
        $sizes = array_column($icons, 'sizes');
        $this->add(in_array('192x192', $sizes, true) ? 'ok' : 'error', 'Icon 192×192 (php artisan pwa:icons)');
        $this->add(in_array('512x512', $sizes, true) ? 'ok' : 'error', 'Icon 512×512 (php artisan pwa:icons)');
        $this->add(in_array('maskable', array_column($icons, 'purpose'), true) ? 'ok' : 'warn', 'Maskable icon for Android');

        $iconPath = trim(config()->string('pwa.icons.path', 'pwa/icons'), '/');
        $this->add(File::exists(public_path($iconPath.'/apple-touch-icon.png')) ? 'ok' : 'warn', 'apple-touch-icon for iPhone and iPad');
        $splash = count(SplashScreens::links(public_path($iconPath.'/splash'), $iconPath.'/splash'));
        $this->add($splash > 0 ? 'ok' : 'warn', "iOS splash screens ({$splash}, php artisan pwa:icons --splash)");

        $this->checkScreenshots(is_array($manifest['screenshots'] ?? null) ? $manifest['screenshots'] : []);

        $scope = Value::string($manifest['scope'] ?? '/', '/');
        $start = Value::string(parse_url(Value::string($manifest['start_url'] ?? '/', '/'), PHP_URL_PATH));
        $this->add(str_starts_with($start ?: '/', $scope) ? 'ok' : 'error', 'start_url inside scope');
    }

    /**
     * Chrome shows the richer install dialog only with screenshots, and drops ones whose size or type is wrong.
     *
     * @param  array<array-key, mixed>  $screenshots
     */
    protected function checkScreenshots(array $screenshots): void
    {
        $factors = [];

        foreach ($screenshots as $screenshot) {
            $screenshot = is_array($screenshot) ? $screenshot : [];
            $src = Value::string($screenshot['src'] ?? null);
            $factors[] = Value::string($screenshot['form_factor'] ?? null, 'narrow');

            if ($src === '' || preg_match('#^(https?:)?//#i', $src) === 1) {
                continue;   // remote files are not checked
            }

            $file = public_path(ltrim(Value::string(parse_url($src, PHP_URL_PATH)), '/'));
            $info = is_file($file) ? @getimagesize($file) : false;

            if ($info === false) {
                $this->add('error', "Screenshot {$src} exists and is an image");

                continue;
            }

            $sizes = Value::string($screenshot['sizes'] ?? null);
            $type = Value::string($screenshot['type'] ?? null);
            $actual = "{$info[0]}x{$info[1]}";
            $this->add(in_array($actual, explode(' ', $sizes), true) ? 'ok' : 'error', "Screenshot {$src} sizes \"{$sizes}\" match the file ({$actual})");

            if ($type !== '') {
                $this->add($type === $info['mime'] ? 'ok' : 'error', "Screenshot {$src} type \"{$type}\" matches the file ({$info['mime']})");
            }
        }

        $this->add(
            in_array('wide', $factors, true) && in_array('narrow', $factors, true) ? 'ok' : 'warn',
            'Screenshots with form_factor wide and narrow (richer install dialog)',
        );
    }

    protected function checkServiceWorker(ServiceWorkerBuilder $builder): void
    {
        $swPath = ltrim(config()->string('pwa.sw.url', '/sw.js'), '/');
        $this->add(
            File::exists(public_path($swPath)) ? 'error' : 'ok',
            "public/{$swPath} does not exist (otherwise the web server serves it even after pwa:disable)",
        );

        $this->add(
            File::exists(public_path(config()->string('pwa.sw.vite_manifest', 'build/manifest.json'))) ? 'ok' : 'warn',
            'Vite manifest for precache (npm run build)',
        );

        if (! $this->checkCacheRules($builder)) {
            return;
        }

        if (! $builder->isCompiled()) {
            $this->add('warn', 'Service worker built (php artisan pwa:build)');
        } else {
            $fresh = Value::string($builder->runtimeConfig()['version'] ?? null);
            $built = $builder->versionOf(Value::string(File::get($builder->compiledPath())));
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
            static fn (mixed $path): string => str_replace('*', 'x', Value::string($path)),
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

        $needed = ['manifest.webmanifest', ltrim(config()->string('pwa.sw.url', '/sw.js'), '/'), trim(config()->string('pwa.route_prefix', 'pwa'), '/').'/*'];
        $missing = array_values(array_diff($needed, array_map(fn ($p): string => trim(Value::string($p), '/'), $except)));

        $this->add(
            $missing === [] ? 'ok' : 'warn',
            $missing === [] ? 'Maintenance mode exceptions' : 'Maintenance mode exceptions missing: '.implode(', ', $missing).' (bootstrap/app.php)',
        );
    }

    protected function checkSession(): void
    {
        $this->add(config('session.driver') === 'array' ? 'error' : 'ok', 'Session driver persists data ('.Value::string(config('session.driver')).')');

        $hasRemember = collect(RouteFacade::getRoutes()->getRoutes())
            ->contains(fn (Route $route): bool => in_array('pwa.remember', $route->gatherMiddleware(), true));
        $this->add($hasRemember ? 'ok' : 'warn', 'pwa.remember middleware on the login route');

        $hasCache = collect(RouteFacade::getRoutes()->getRoutes())
            ->contains(fn (Route $route): bool => in_array('pwa.cache', $route->gatherMiddleware(), true));
        $this->add($hasCache ? 'ok' : 'warn', 'Some pages available offline (pwa.cache middleware)');

        $store = Value::string(config('pwa.sw.kill_switch_store') ?? config('cache.default'));
        $this->add(in_array($store, ['file', 'array'], true) ? 'warn' : 'ok', "Kill switch store shared between servers ({$store})");
    }

    protected function add(string $level, string $message): void
    {
        $this->results[] = ['level' => $level, 'message' => $message];
    }
}
