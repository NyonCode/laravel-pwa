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

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
