<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\ServiceWorker;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use NyonCode\LaravelPwa\Events\ServiceWorkerBuilt;
use NyonCode\LaravelPwa\Pwa;
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
        $offline = config()->boolean('pwa.offline.enabled', true);

        $config = [
            'precache' => $this->precache(),
            'offlineUrl' => $offline ? config()->string('pwa.offline.route', '/offline') : null,
            'queue' => config()->boolean('pwa.offline.queue', true),
            'navigationTimeout' => config()->integer('pwa.cache.navigation_timeout', 3) * 1000,
            'neverCache' => $this->stringList('pwa.cache.never_cache'),
            'rules' => array_map(static fn (CacheRule $rule): array => $rule->toArray(), $this->rules()),
            'buildPrefix' => '/'.trim(config()->string('pwa.sw.build_directory', 'build'), '/').'/',
            'telemetry' => config()->boolean('pwa.telemetry.enabled', true) ? $prefix.'/telemetry' : null,
            'sessionUrl' => $prefix.'/session',
            'warm' => app(Pwa::class)->warmsPages(),
            'scope' => config()->string('pwa.scope', '/'),
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
     * Precache: the offline page, URLs from the config and the Vite manifest entries.
     *
     * @return list<string>
     */
    public function precache(): array
    {
        $urls = [];

        if (config()->boolean('pwa.offline.enabled', true)) {
            $urls[] = config()->string('pwa.offline.route', '/offline');
        }

        foreach ($this->stringList('pwa.offline.precache') as $url) {
            $urls[] = $url;
        }

        foreach ($this->viteFiles() as $file) {
            $urls[] = $file;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Files of the Vite manifest (entry points and their CSS), prefixed with the build directory.
     *
     * @return list<string>
     */
    public function viteFiles(): array
    {
        $manifestPath = public_path(config()->string('pwa.sw.vite_manifest', 'build/manifest.json'));

        if (! File::exists($manifestPath)) {
            return [];
        }

        $manifest = json_decode(File::get($manifestPath), true);

        if (! is_array($manifest)) {
            throw new RuntimeException("The Vite manifest [{$manifestPath}] is not valid JSON.");
        }

        $prefix = '/'.trim(config()->string('pwa.sw.build_directory', 'build'), '/').'/';
        $files = [];

        foreach ($manifest as $chunk) {
            if (! is_array($chunk) || ! ($chunk['isEntry'] ?? false)) {
                continue;
            }

            if (isset($chunk['file']) && is_string($chunk['file'])) {
                $files[] = $prefix.$chunk['file'];
            }

            foreach ((array) ($chunk['css'] ?? []) as $css) {
                if (is_string($css)) {
                    $files[] = $prefix.$css;
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
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
