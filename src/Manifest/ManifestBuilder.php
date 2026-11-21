<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Manifest;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * Skládá webový manifest z configu a z fluent volání aplikace.
 *
 * Fluent volání (např. v AppServiceProvider::boot) mají přednost před configem.
 */
class ManifestBuilder
{
    /** @var array<string, mixed> */
    protected array $overrides = [];

    /** @var list<array<string, mixed>> */
    protected array $shortcuts = [];

    /** @var list<array<string, mixed>> */
    protected array $fileHandlers = [];

    /** @var list<array<string, string>> */
    protected array $protocolHandlers = [];

    /** @var list<string> */
    protected array $displayOverride = [];

    public function set(string $key, mixed $value): static
    {
        $this->overrides[$key] = $value;

        return $this;
    }

    public function shortcut(string $name, string $url, ?string $icon = null, ?string $description = null): static
    {
        $this->shortcuts[] = array_filter([
            'name' => $name,
            'url' => $url,
            'description' => $description,
            'icons' => $icon ? [['src' => $icon, 'sizes' => '96x96']] : null,
        ]);

        return $this;
    }

    /**
     * @param  list<string>  $accept  MIME typy, např. ['image/*', 'application/pdf']
     */
    public function shareTarget(string $action, string $method = 'POST', array $accept = [], string $filesField = 'files'): static
    {
        $method = strtoupper($method);

        $target = [
            'action' => $action,
            'method' => $method,
            'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url'],
        ];

        if ($method === 'POST') {
            $target['enctype'] = $accept === [] ? 'application/x-www-form-urlencoded' : 'multipart/form-data';
        }

        if ($accept !== []) {
            $target['params']['files'] = [['name' => $filesField, 'accept' => $accept]];
        }

        return $this->set('share_target', $target);
    }

    /**
     * @param  array<string, list<string>>  $accept  např. ['text/csv' => ['.csv']]
     */
    public function fileHandler(string $action, array $accept, ?string $name = null): static
    {
        $this->fileHandlers[] = array_filter(['action' => $action, 'accept' => $accept, 'name' => $name]);

        return $this;
    }

    public function protocolHandler(string $protocol, string $url): static
    {
        $this->protocolHandlers[] = ['protocol' => $protocol, 'url' => $url];

        return $this;
    }

    public function windowControlsOverlay(): static
    {
        return $this->prependDisplayOverride('window-controls-overlay');
    }

    /**
     * @param  array<string, mixed>  $tabStrip
     */
    public function tabbed(array $tabStrip = []): static
    {
        if ($tabStrip !== []) {
            $this->set('tab_strip', $tabStrip);
        }

        return $this->prependDisplayOverride('tabbed');
    }

    /**
     * @param  string|list<string>  $clientMode  navigate-existing | focus-existing | navigate-new | auto
     */
    public function launchHandler(string|array $clientMode): static
    {
        return $this->set('launch_handler', ['client_mode' => $clientMode]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Request $request = null): array
    {
        $request ??= request();
        $locale = app()->getLocale();

        $name = $this->translated('name', (string) config('pwa.name'));
        $shortName = $this->translated('short_name', (string) (config('pwa.short_name') ?: $name));

        $manifest = [
            'id' => (string) config('pwa.id', '/'),
            'name' => $name,
            'short_name' => $shortName,
            'description' => $this->translated('description', (string) config('pwa.description', '')) ?: null,
            'lang' => str_replace('_', '-', $locale),
            'dir' => in_array(substr($locale, 0, 2), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr',
            'start_url' => (string) config('pwa.start_url', '/'),
            'scope' => (string) config('pwa.scope', '/'),
            ...DisplayStrategy::fromConfig()->resolve($request),
            'orientation' => config('pwa.orientation'),
            'theme_color' => (string) config('pwa.theme.light', '#ffffff'),
            'background_color' => (string) config('pwa.theme.background', '#ffffff'),
            'categories' => config('pwa.categories') ?: null,
            'icons' => $this->icons(),
            'screenshots' => config('pwa.screenshots') ?: null,
            'shortcuts' => array_values([...(array) config('pwa.shortcuts', []), ...$this->shortcuts]) ?: null,
            'share_target' => config('pwa.share_target'),
            'file_handlers' => array_values([...(array) config('pwa.file_handlers', []), ...$this->fileHandlers]) ?: null,
            'protocol_handlers' => array_values([...(array) config('pwa.protocol_handlers', []), ...$this->protocolHandlers]) ?: null,
            'launch_handler' => config('pwa.launch_handler'),
            'scope_extensions' => config('pwa.scope_extensions') ?: null,
        ];

        if ($this->displayOverride !== []) {
            $manifest['display_override'] = array_values(array_unique([
                ...$this->displayOverride,
                ...(array) ($manifest['display_override'] ?? []),
            ]));
        }

        $manifest = [...$manifest, ...$this->localized(), ...$this->overrides];

        return array_filter($manifest, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    public function toJson(?Request $request = null): string
    {
        return (string) json_encode($this->toArray($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Ikony z public/{icons.path}, které vygeneroval pwa:icons. Jen existující soubory.
     *
     * @return list<array<string, string>>
     */
    public function icons(): array
    {
        $path = trim((string) config('pwa.icons.path', 'pwa/icons'), '/');
        $icons = [];

        foreach ((array) config('pwa.icons.sizes', [192, 512]) as $size) {
            $file = "icon-{$size}.png";

            if (File::exists(public_path("{$path}/{$file}"))) {
                $icons[] = ['src' => "/{$path}/{$file}", 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
            }
        }

        if (File::exists(public_path("{$path}/maskable-512.png"))) {
            $icons[] = ['src' => "/{$path}/maskable-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
        }

        if (File::exists(public_path("{$path}/icon.svg"))) {
            $icons[] = ['src' => "/{$path}/icon.svg", 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];
        }

        return $icons;
    }

    /**
     * Lokalizované varianty name / short_name / description (Chromium 147+).
     *
     * @return array<string, array<string, string>>
     */
    protected function localized(): array
    {
        $locales = array_values((array) config('pwa.locales', []));

        if ($locales === []) {
            return [];
        }

        $result = [];

        foreach (['name', 'short_name', 'description'] as $member) {
            foreach ($locales as $locale) {
                $key = "pwa::manifest.{$member}";
                $value = trans($key, [], $locale);

                if (is_string($value) && $value !== $key && $value !== '') {
                    $result["{$member}_localized"][str_replace('_', '-', $locale)] = $value;
                }
            }
        }

        return $result;
    }

    protected function translated(string $member, string $fallback): string
    {
        $key = "pwa::manifest.{$member}";
        $value = trans($key);

        return is_string($value) && $value !== $key && $value !== '' ? $value : $fallback;
    }

    protected function prependDisplayOverride(string $mode): static
    {
        $this->displayOverride = array_values(array_unique([$mode, ...$this->displayOverride]));

        return $this;
    }

    /**
     * Pro testy a Octane: zahodí fluent nastavení.
     */
    public function flush(): void
    {
        $this->overrides = $this->shortcuts = $this->fileHandlers = $this->protocolHandlers = $this->displayOverride = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        return Arr::only(get_object_vars($this), ['overrides', 'shortcuts', 'fileHandlers', 'protocolHandlers', 'displayOverride']);
    }
}
