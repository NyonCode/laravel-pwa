<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\ServiceWorker;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use NyonCode\LaravelPwa\Events\ServiceWorkerBuilt;
use NyonCode\LaravelPwa\Pwa;
use NyonCode\LaravelPwa\Support\Value;
use RuntimeException;

/**
 * Builds the service worker: configuration + importScripts + the package runtime + extensions.
 *
 * In production `pwa:build` writes it to bootstrap/cache/pwa/sw.js and the route only serves the
 * file. Without a build it is assembled on every request (slower; pwa:doctor warns).
 */
class ServiceWorkerBuilder
{
    /** @var list<CacheRule> */
    protected array $rules = [];

    public function __construct(
        protected string $runtimePath,
        protected string $killSwitchPath,
    ) {}

    /**
     * Adds a caching rule (fluent, e.g. in AppServiceProvider::boot). Fluent rules are checked after
     * the `pwa.cache.rules` config and before `pwa.cache.api` and the default image/font rule.
     */
    public function cache(?string $match): CacheRule
    {
        return $this->rules[] = CacheRule::for($match);
    }

    /**
     * Every rule in the order the worker checks them; the first match wins.
     *
     * @return list<CacheRule>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (config()->array('pwa.cache.rules', []) as $index => $rule) {
            if (! is_array($rule)) {
                throw new InvalidArgumentException("pwa.cache.rules.{$index} must be an array.");
            }

            try {
                $rules[] = CacheRule::fromArray($rule);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("pwa.cache.rules.{$index}: {$e->getMessage()}", $e->getCode(), previous: $e);
            }
        }

        $rules = [...$rules, ...$this->rules];

        foreach ($this->stringList('pwa.cache.api') as $pattern) {
            $rules[] = CacheRule::for($pattern)->networkFirst()->name('api');
        }

        $images = config()->array('pwa.cache.images', []);

        if (($images['enabled'] ?? true) !== false) {
            $default = CacheRule::for(null)->destination('image', 'font')->staleWhileRevalidate()->name('images')
                ->maxEntries(is_int($images['max_entries'] ?? null) ? $images['max_entries'] : 120);

            if (is_int($images['max_age'] ?? null) && $images['max_age'] > 0) {
                $default->maxAge($images['max_age']);
            }

            $rules[] = $default;
        }

        $this->assertConsistentNames($rules);

        return $rules;
    }

    /**
     * Drops fluent rules (tests, Octane workers that rebuild the configuration).
     */
    public function flush(): void
    {
        $this->rules = [];
    }

    public function compiledPath(): string
    {
        return base_path(trim(config()->string('pwa.sw.compiled_path', 'bootstrap/cache/pwa'), '/').'/sw.js');
    }

    public function isCompiled(): bool
    {
        return File::exists($this->compiledPath());
    }

    /**
     * The worker: from the build, or assembled now.
     */
    public function contents(): string
    {
        return $this->isCompiled() ? File::get($this->compiledPath()) : $this->build();
    }

    public function killSwitch(): string
    {
        return File::get($this->killSwitchPath);
    }

    /**
     * Writes the assembled worker and returns its version.
     */
    public function write(): string
    {
        $contents = $this->build();
        $path = $this->compiledPath();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        $version = $this->versionOf($contents);
        ServiceWorkerBuilt::dispatch($version, $path, $this->precache());

        return $version;
    }

    public function clear(): bool
    {
        return ! $this->isCompiled() || File::delete($this->compiledPath());
    }

    public function build(): string
    {
        $script = '/* nyoncode/laravel-pwa service worker. Generated, do not edit. */'."\n"
            .'self.__PWA = '.json_encode($this->runtimeConfig(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).";\n"
            .$this->importScripts($this->imports())
            ."\n".File::get($this->runtimePath);

        $extensions = $this->extensions();

        if ($extensions !== []) {
            $script .= "\n/* sw.extend: application routes and handlers using self.PwaWorker. */\n".$this->importScripts($extensions);
        }

        return $script;
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeConfig(): array
    {
        $prefix = '/'.trim(config()->string('pwa.route_prefix', 'pwa'), '/');
        $offline = Value::configBool('pwa.offline.enabled', true);

        $config = [
            'precache' => $this->precache(),
            'offlineUrl' => $offline ? config()->string('pwa.offline.route', '/offline') : null,
            'queue' => Value::configBool('pwa.offline.queue', true),
            'navigationTimeout' => Value::configInt('pwa.cache.navigation_timeout', 3) * 1000,
            'neverCache' => $this->stringList('pwa.cache.never_cache'),
            'rules' => array_map(static fn (CacheRule $rule): array => $rule->toArray(), $this->rules()),
            'buildPrefix' => '/'.trim(config()->string('pwa.sw.build_directory', 'build'), '/').'/',
            'telemetry' => Value::configBool('pwa.telemetry.enabled', true) ? $prefix.'/telemetry' : null,
            'sessionUrl' => $prefix.'/session',
            'client' => strtok(app(Pwa::class)->clientUrl(), '?'),
            'warm' => app(Pwa::class)->warmsPages(),
            'scope' => config()->string('pwa.scope', '/'),
            'push' => $this->pushConfig($prefix),
        ];

        $config['version'] = substr(hash('sha256', json_encode([
            $config,
            $this->imports(),
            $this->extensions(),
            $this->fingerprint([...$this->imports(), ...$this->extensions()]),
            File::exists($this->runtimePath) ? hash_file('sha256', $this->runtimePath) : null,
        ], JSON_THROW_ON_ERROR)), 0, 12);

        return $config;
    }

    /**
     * Built-in push listeners (pwa.push.handle_in_worker), or null when the app brings its own.
     *
     * @return array<string, mixed>|null
     */
    protected function pushConfig(string $prefix): ?array
    {
        if (! app(Pwa::class)->pushEnabled() || ! Value::configBool('pwa.push.handle_in_worker', true)) {
            return null;
        }

        $icons = '/'.trim(config()->string('pwa.icons.path', 'pwa/icons'), '/');
        $existing = static fn (string $path): ?string => File::exists(public_path(ltrim($path, '/'))) ? $path : null;

        return [
            'key' => config('pwa.push.vapid.public_key'),
            'url' => $prefix.'/push/subscriptions',
            'events' => Value::configBool('pwa.push.analytics', false) ? $prefix.'/push/events' : null,
            'name' => Value::string(config('pwa.short_name')) ?: Value::string(config('pwa.name'), 'Laravel'),
            'icon' => config('pwa.push.icon') ?? $existing($icons.'/icon-192.png'),
            'badge' => config('pwa.push.badge') ?? $existing($icons.'/monochrome-512.png'),
            // Shown instead of the content on a device where another user is signed in.
            'fallback' => __('pwa::messages.push_fallback'),
        ];
    }

    /**
     * Precache: the offline page, the browser client, URLs from the config and the Vite manifest entries.
     *
     * @return list<string>
     */
    public function precache(): array
    {
        $urls = [];

        if (Value::configBool('pwa.offline.enabled', true)) {
            $urls[] = config()->string('pwa.offline.route', '/offline');
        }

        // Pages served from the cache offline still need the client (status bar, queue, install).
        $urls[] = app(Pwa::class)->clientUrl();

        foreach ($this->stringList('pwa.offline.precache') as $url) {
            $urls[] = $url;
        }

        foreach ($this->viteFiles() as $file) {
            $urls[] = $file;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Files of the Vite manifest, prefixed with the build directory (`pwa.sw.precache_vite`):
     *
     * - `imports` (default): entry points with their static imports, CSS and assets (fonts, images),
     *   everything an entry needs to run offline;
     * - `entries`: entry points and their CSS only;
     * - `all`: every chunk, including dynamic imports;
     * - `false`: nothing from Vite.
     *
     * `pwa.sw.precache_exclude` drops files matching a pattern (`Str::is`, relative to the build directory).
     *
     * @return list<string>
     */
    public function viteFiles(): array
    {
        $mode = config('pwa.sw.precache_vite', 'imports');

        if (in_array($mode, [false, null, 'none'], true)) {
            return [];
        }

        if (! in_array($mode, ['imports', 'entries', 'all'], true)) {
            throw new RuntimeException('[pwa.sw.precache_vite] must be imports, entries, all or false.');
        }

        $manifestPath = public_path(config()->string('pwa.sw.vite_manifest', 'build/manifest.json'));

        if (! File::exists($manifestPath)) {
            return [];
        }

        $manifest = json_decode(File::get($manifestPath), true);

        if (! is_array($manifest)) {
            throw new RuntimeException("The Vite manifest [{$manifestPath}] is not valid JSON.");
        }

        $chunks = array_filter($manifest, is_array(...));
        $keys = match ($mode) {
            'all' => array_keys($chunks),
            'entries' => array_keys(array_filter($chunks, static fn (array $chunk): bool => (bool) ($chunk['isEntry'] ?? false))),
            default => $this->withImports($chunks),
        };

        $exclude = $this->stringList('pwa.sw.precache_exclude');
        $prefix = '/'.trim(config()->string('pwa.sw.build_directory', 'build'), '/').'/';
        $files = [];

        foreach ($keys as $key) {
            $chunk = $chunks[$key];
            $own = [$chunk['file'] ?? null, ...(array) ($chunk['css'] ?? [])];

            if ($mode !== 'entries') {
                $own = [...$own, ...(array) ($chunk['assets'] ?? [])];
            }

            foreach ($own as $file) {
                if (is_string($file) && $file !== '' && ! Str::is($exclude, $file)) {
                    $files[] = $prefix.$file;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * Entry chunks and every chunk they import statically (recursively).
     *
     * @param  array<array-key, array<array-key, mixed>>  $chunks
     * @return list<array-key>
     */
    protected function withImports(array $chunks): array
    {
        $queue = array_keys(array_filter($chunks, static fn (array $chunk): bool => (bool) ($chunk['isEntry'] ?? false)));
        $seen = [];

        while ($queue !== []) {
            $key = array_shift($queue);

            if (isset($seen[$key]) || ! isset($chunks[$key])) {
                continue;
            }

            $seen[$key] = true;

            foreach ((array) ($chunks[$key]['imports'] ?? []) as $import) {
                if (is_string($import) || is_int($import)) {
                    $queue[] = $import;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Total size of the precached files found in public/ (bytes); routes like /offline are not counted.
     */
    public function precacheBytes(): int
    {
        $bytes = 0;

        foreach ($this->precache() as $url) {
            $path = public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));
            $bytes += is_file($path) ? (int) filesize($path) : 0;
        }

        return $bytes;
    }

    /**
     * Scripts loaded before the runtime (`pwa.sw.import`), e.g. a push system's listeners.
     *
     * @return list<string>
     */
    public function imports(): array
    {
        return $this->stringList('pwa.sw.import');
    }

    /**
     * Scripts loaded after the runtime (`pwa.sw.extend`); they can use `self.PwaWorker`.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return $this->stringList('pwa.sw.extend');
    }

    public function versionOf(string $contents): string
    {
        return preg_match('/"version":"([a-f0-9]+)"/', $contents, $m) === 1 ? $m[1] : substr(hash('sha256', $contents), 0, 12);
    }

    public function version(): string
    {
        return $this->versionOf($this->contents());
    }

    /**
     * @param  list<string>  $urls
     */
    protected function importScripts(array $urls): string
    {
        if ($urls === []) {
            return '';
        }

        return 'importScripts('.implode(', ', array_map(
            static fn (string $url): string => json_encode($url, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $urls,
        )).");\n";
    }

    /**
     * A change in an imported local file (e.g. push-sw.js) must change the worker version.
     *
     * @param  list<string>  $urls
     * @return array<string, string|null>
     */
    protected function fingerprint(array $urls): array
    {
        $result = [];

        foreach ($urls as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            $local = parse_url($url, PHP_URL_HOST) === null && is_string($path) ? public_path(ltrim($path, '/')) : null;
            $result[$url] = $local !== null && File::exists($local) ? (hash_file('sha256', $local) ?: null) : null;
        }

        return $result;
    }

    /**
     * Rules may share a cache name only with the same sharing mode: otherwise one user's data could
     * end up in a cache that every user reads.
     *
     * @param  list<CacheRule>  $rules
     */
    protected function assertConsistentNames(array $rules): void
    {
        $shared = [];

        foreach ($rules as $rule) {
            $name = $rule->getName();

            if (isset($shared[$name]) && $shared[$name] !== $rule->isShared()) {
                throw new InvalidArgumentException("Cache name [{$name}] is used by both a shared and a per-user rule.");
            }

            $shared[$name] = $rule->isShared();
        }
    }

    /**
     * @return list<string>
     */
    protected function stringList(string $key): array
    {
        $values = config($key, []);

        if ($values === null) {
            return [];
        }

        if (! is_array($values)) {
            throw new InvalidArgumentException("[{$key}] must be a list of strings.");
        }

        return array_values(array_map(static function (mixed $value) use ($key): string {
            if (! is_string($value)) {
                throw new InvalidArgumentException("[{$key}] must be a list of strings.");
            }

            return $value;
        }, $values));
    }
}
