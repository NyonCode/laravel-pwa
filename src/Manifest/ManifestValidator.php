<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Manifest;

/**
 * Checks a manifest against what browsers require for installation.
 *
 * Errors block installation in at least one major browser; warnings degrade it
 * (no maskable icon on Android, a long short_name cut on the home screen …).
 */
final class ManifestValidator
{
    public const DISPLAY_MODES = ['fullscreen', 'standalone', 'minimal-ui', 'browser'];

    public const CLIENT_MODES = ['navigate-existing', 'navigate-new', 'focus-existing', 'auto'];

    /** Schemes a protocol handler may register without the web+ prefix (HTML spec safelist). */
    public const SAFELISTED_SCHEMES = [
        'bitcoin', 'ftp', 'ftps', 'geo', 'im', 'irc', 'ircs', 'magnet', 'mailto', 'matrix', 'mms', 'news',
        'nntp', 'openpgp4fpr', 'sftp', 'sip', 'sms', 'smsto', 'ssh', 'tel', 'urn', 'webcal', 'wtai', 'xmpp',
    ];

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $manifest): array
    {
        $errors = [];
        $warnings = [];

        foreach (['name', 'start_url'] as $member) {
            if (! is_string($manifest[$member] ?? null) || $manifest[$member] === '') {
                $errors[] = "Missing [{$member}].";
            }
        }

        if (! in_array($manifest['display'] ?? null, self::DISPLAY_MODES, true)) {
            $errors[] = '[display] must be one of '.implode(', ', self::DISPLAY_MODES).'.';
        }

        $icons = is_array($manifest['icons'] ?? null) ? array_values(array_filter($manifest['icons'], is_array(...))) : [];
        $field = static fn (array $icon, string $key, string $default): string => is_string($icon[$key] ?? null) ? $icon[$key] : $default;
        $any = array_filter($icons, static fn (array $icon): bool => in_array('any', explode(' ', $field($icon, 'purpose', 'any')), true));
        $sizes = array_merge([], ...array_map(static fn (array $icon): array => explode(' ', $field($icon, 'sizes', '')), array_values($any)));

        foreach (['192x192', '512x512'] as $size) {
            if (! in_array($size, $sizes, true) && ! in_array('any', $sizes, true)) {
                $errors[] = "No {$size} icon with purpose \"any\" (php artisan pwa:icons).";
            }
        }

        if (array_filter($icons, static fn (array $icon): bool => str_contains($field($icon, 'purpose', ''), 'maskable')) === []) {
            $warnings[] = 'No maskable icon: Android shows the icon on a white circle.';
        }

        if (! is_string($manifest['id'] ?? null)) {
            $warnings[] = 'No [id]: browsers derive it from start_url, so changing start_url creates a new app.';
        }

        if (is_string($manifest['short_name'] ?? null) && mb_strlen($manifest['short_name']) > 12) {
            $warnings[] = '[short_name] is longer than 12 characters and may be cut on the home screen.';
        }

        foreach (['theme_color', 'background_color'] as $member) {
            if (isset($manifest[$member]) && (! is_string($manifest[$member]) || preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $manifest[$member]) !== 1)) {
                $warnings[] = "[{$member}] should be a #rgb or #rrggbb colour.";
            }
        }

        return ['errors' => $errors, 'warnings' => [...$warnings, ...$this->capabilities($manifest)]];
    }

    /**
     * Problems in the OS integration members (shortcuts, share_target, file_handlers,
     * protocol_handlers, launch_handler). Browsers silently ignore invalid entries.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return list<string>
     */
    public function capabilities(array $manifest): array
    {
        $scope = is_string($manifest['scope'] ?? null) ? $manifest['scope'] : '/';

        return [
            ...$this->shortcuts($manifest['shortcuts'] ?? null, $scope),
            ...$this->shareTarget($manifest['share_target'] ?? null, $scope),
            ...$this->fileHandlers($manifest['file_handlers'] ?? null, $scope),
            ...$this->protocolHandlers($manifest['protocol_handlers'] ?? null, $scope),
            ...$this->launchHandler($manifest['launch_handler'] ?? null),
        ];
    }

    /**
     * @return list<string>
     */
    private function shortcuts(mixed $shortcuts, string $scope): array
    {
        if ($shortcuts === null) {
            return [];
        }

        if (! is_array($shortcuts)) {
            return ['[shortcuts] must be a list.'];
        }

        $warnings = [];

        foreach (array_values($shortcuts) as $i => $shortcut) {
            if (! is_array($shortcut) || ! $this->filled($shortcut['name'] ?? null)) {
                $warnings[] = "[shortcuts.{$i}] needs a name.";

                continue;
            }

            if (! $this->inScope($shortcut['url'] ?? null, $scope)) {
                $warnings[] = "[shortcuts.{$i}.url] must be a URL inside the scope [{$scope}]; browsers drop the shortcut.";
            }
        }

        if (count($shortcuts) > 10) {
            $warnings[] = '[shortcuts] has more than 10 entries; browsers show only the first few.';
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function shareTarget(mixed $target, string $scope): array
    {
        if ($target === null) {
            return [];
        }

        if (! is_array($target)) {
            return ['[share_target] must be an object.'];
        }

        $warnings = [];
        $method = strtoupper(is_string($target['method'] ?? null) ? $target['method'] : 'GET');

        if (! $this->inScope($target['action'] ?? null, $scope)) {
            $warnings[] = "[share_target.action] must be a URL inside the scope [{$scope}].";
        }

        if (! in_array($method, ['GET', 'POST'], true)) {
            $warnings[] = '[share_target.method] must be GET or POST.';
        }

        $params = is_array($target['params'] ?? null) ? $target['params'] : [];

        if (isset($params['files'])) {
            if ($method !== 'POST' || ($target['enctype'] ?? null) !== 'multipart/form-data') {
                $warnings[] = '[share_target] with files needs method POST and enctype multipart/form-data.';
            }

            foreach (is_array($params['files']) ? array_values($params['files']) : [null] as $i => $file) {
                if (! is_array($file) || ! $this->filled($file['name'] ?? null) || ($file['accept'] ?? []) === []) {
                    $warnings[] = "[share_target.params.files.{$i}] needs a name and accepted MIME types.";
                }
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function fileHandlers(mixed $handlers, string $scope): array
    {
        if ($handlers === null) {
            return [];
        }

        if (! is_array($handlers)) {
            return ['[file_handlers] must be a list.'];
        }

        $warnings = [];

        foreach (array_values($handlers) as $i => $handler) {
            if (! is_array($handler) || ! $this->inScope($handler['action'] ?? null, $scope)) {
                $warnings[] = "[file_handlers.{$i}.action] must be a URL inside the scope [{$scope}].";

                continue;
            }

            $accept = $handler['accept'] ?? null;
            $valid = is_array($accept) && $accept !== [];

            foreach ($valid ? $accept : [] as $mime => $extensions) {
                $list = is_array($extensions) ? $extensions : [$extensions];
                $valid = $valid && is_string($mime) && str_contains($mime, '/')
                    && $list !== [] && array_filter($list, static fn (mixed $ext): bool => ! is_string($ext) || ! str_starts_with($ext, '.')) === [];
            }

            if (! $valid) {
                $warnings[] = "[file_handlers.{$i}.accept] must map MIME types to extensions starting with a dot, e.g. {\"text/csv\": [\".csv\"]}.";
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function protocolHandlers(mixed $handlers, string $scope): array
    {
        if ($handlers === null) {
            return [];
        }

        if (! is_array($handlers)) {
            return ['[protocol_handlers] must be a list.'];
        }

        $warnings = [];

        foreach (array_values($handlers) as $i => $handler) {
            $protocol = is_array($handler) && is_string($handler['protocol'] ?? null) ? strtolower($handler['protocol']) : '';
            $url = is_array($handler) ? ($handler['url'] ?? null) : null;

            if (preg_match('/^web\+[a-z]+$/', $protocol) !== 1 && ! in_array($protocol, self::SAFELISTED_SCHEMES, true)) {
                $warnings[] = "[protocol_handlers.{$i}.protocol] must be web+name (lowercase letters) or a safelisted scheme such as mailto.";
            }

            if (! is_string($url) || ! str_contains($url, '%s') || ! $this->inScope($url, $scope)) {
                $warnings[] = "[protocol_handlers.{$i}.url] must be a URL inside the scope [{$scope}] containing %s.";
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function launchHandler(mixed $handler): array
    {
        if ($handler === null) {
            return [];
        }

        $modes = is_array($handler) ? (array) ($handler['client_mode'] ?? []) : [];

        if ($modes === [] || array_filter($modes, static fn (mixed $mode): bool => ! in_array($mode, self::CLIENT_MODES, true)) !== []) {
            return ['[launch_handler.client_mode] must be one or more of '.implode(', ', self::CLIENT_MODES).'.'];
        }

        return [];
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * A relative or same-site path under the scope (absolute URLs are left to the browser).
     */
    private function inScope(mixed $url, string $scope): bool
    {
        if (! $this->filled($url)) {
            return false;
        }

        /** @var string $url */
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return true;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return str_starts_with($path, rtrim(parse_url($scope, PHP_URL_PATH) ?: '/', '/').'/') || $path === rtrim($scope, '/');
    }
}
