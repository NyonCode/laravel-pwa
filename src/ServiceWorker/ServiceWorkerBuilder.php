<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\ServiceWorker;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Sestavuje service worker: konfigurace + importScripts + runtime z balíčku.
 *
 * V produkci ho `pwa:build` zapíše do bootstrap/cache/pwa/sw.js a route ho
 * jen vrací. Bez buildu se sestaví za běhu (pomalejší, doctor upozorní).
 */
class ServiceWorkerBuilder
{
    public function __construct(
        protected string $runtimePath,
        protected string $killSwitchPath,
    ) {}

    public function compiledPath(): string
    {
        return base_path(trim((string) config('pwa.sw.compiled_path', 'bootstrap/cache/pwa'), '/').'/sw.js');
    }

    public function isCompiled(): bool
    {
        return File::exists($this->compiledPath());
    }

    /**
     * Obsah SW: z buildu, nebo sestavený za běhu.
     */
    public function contents(): string
    {
        return $this->isCompiled() ? (string) File::get($this->compiledPath()) : $this->build();
    }

    public function killSwitch(): string
    {
        return (string) File::get($this->killSwitchPath);
    }

    /**
     * Zapíše sestavený SW a vrátí jeho verzi.
     */
    public function write(): string
    {
        $contents = $this->build();
        $path = $this->compiledPath();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        return $this->versionOf($contents);
    }

    public function clear(): bool
    {
        return ! $this->isCompiled() || File::delete($this->compiledPath());
    }

    public function build(): string
    {
        $config = $this->runtimeConfig();
        $imports = $this->imports();

        $header = '/* nyoncode/laravel-pwa service worker — generováno, needitovat. */'."\n"
            .'self.__PWA = '.json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).";\n";

        if ($imports !== []) {
            $header .= 'importScripts('.implode(', ', array_map(
                static fn (string $url): string => (string) json_encode($url, JSON_UNESCAPED_SLASHES),
                $imports,
            )).");\n";
        }

        $runtime = File::get($this->runtimePath);

        return $header."\n".$runtime;
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeConfig(): array
    {
        $precache = $this->precache();

        $config = [
            'precache' => $precache,
            'offlineUrl' => config('pwa.offline.enabled', true) ? (string) config('pwa.offline.route', '/offline') : null,
            'queue' => (bool) config('pwa.offline.queue', true),
            'navigationTimeout' => (int) config('pwa.cache.navigation_timeout', 3) * 1000,
            'api' => array_values((array) config('pwa.cache.api', [])),
            'neverCache' => array_values((array) config('pwa.cache.never_cache', [])),
            'images' => (array) config('pwa.cache.images', []),
            'buildPrefix' => '/'.trim((string) config('pwa.sw.build_directory', 'build'), '/').'/',
            'telemetry' => config('pwa.telemetry.enabled', true)
                ? '/'.trim((string) config('pwa.route_prefix', 'pwa'), '/').'/telemetry'
                : null,
            'sessionUrl' => '/'.trim((string) config('pwa.route_prefix', 'pwa'), '/').'/session',
            'scope' => (string) config('pwa.scope', '/'),
        ];

        $config['version'] = substr(hash('sha256', json_encode([
            $config,
            $this->imports(),
            $this->importsFingerprint(),
            File::exists($this->runtimePath) ? hash_file('sha256', $this->runtimePath) : null,
        ]) ?: ''), 0, 12);

        return $config;
    }

    /**
     * Precache: offline stránka, ručně zadané URL a vstupy z Vite manifestu.
     *
     * @return list<string>
     */
    public function precache(): array
    {
        $urls = [];

        if (config('pwa.offline.enabled', true)) {
            $urls[] = (string) config('pwa.offline.route', '/offline');
        }

        foreach ((array) config('pwa.offline.precache', []) as $url) {
            $urls[] = (string) $url;
        }

        foreach ($this->viteFiles() as $file) {
            $urls[] = $file;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Soubory z Vite manifestu (entry body + jejich CSS), s prefixem build adresáře.
     *
     * @return list<string>
     */
    public function viteFiles(): array
    {
        $manifestPath = public_path((string) config('pwa.sw.vite_manifest', 'build/manifest.json'));

        if (! File::exists($manifestPath)) {
            return [];
        }

        $manifest = json_decode((string) File::get($manifestPath), true);

        if (! is_array($manifest)) {
            throw new RuntimeException("Vite manifest [{$manifestPath}] není platný JSON.");
        }

        $prefix = '/'.trim((string) config('pwa.sw.build_directory', 'build'), '/').'/';
        $files = [];

        foreach ($manifest as $chunk) {
            if (! is_array($chunk) || ! ($chunk['isEntry'] ?? false)) {
                continue;
            }

            if (isset($chunk['file'])) {
                $files[] = $prefix.$chunk['file'];
            }

            foreach ((array) ($chunk['css'] ?? []) as $css) {
                $files[] = $prefix.$css;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @return list<string>
     */
    public function imports(): array
    {
        return array_values(array_map(strval(...), (array) config('pwa.sw.import', [])));
    }

    /**
     * Změna importovaného souboru (např. push-sw.js) musí změnit verzi SW.
     *
     * @return array<string, string|null>
     */
    protected function importsFingerprint(): array
    {
        $result = [];

        foreach ($this->imports() as $url) {
            $local = parse_url($url, PHP_URL_HOST) === null ? public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/')) : null;
            $result[$url] = $local !== null && File::exists($local) ? (hash_file('sha256', $local) ?: null) : null;
        }

        return $result;
    }

    public function versionOf(string $contents): string
    {
        return preg_match('/"version":"([a-f0-9]+)"/', $contents, $m) === 1 ? $m[1] : substr(hash('sha256', $contents), 0, 12);
    }

    public function version(): string
    {
        return $this->versionOf($this->contents());
    }
}
