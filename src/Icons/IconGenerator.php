<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Icons;

use Illuminate\Support\Facades\File;
use NyonCode\LaravelPwa\Support\Color;
use RuntimeException;

/**
 * Generates the PWA icons and iOS startup images from one source image (used by pwa:icons).
 *
 * @internal
 */
class IconGenerator
{
    public function __construct(protected ImageDriver $driver) {}

    /**
     * Imagick when available (reads SVG), otherwise GD.
     *
     * @throws RuntimeException
     */
    public static function for(string $source): self
    {
        $isSvg = strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'svg';

        if (extension_loaded('imagick')) {
            return new self(new ImagickDriver);
        }

        if ($isSvg) {
            throw new RuntimeException('SVG requires the imagick extension. Use a PNG or install imagick.');
        }

        if (! extension_loaded('gd')) {
            throw new RuntimeException('Generating icons requires the gd or imagick extension.');
        }

        return new self(new GdDriver);
    }

    public function driver(): ImageDriver
    {
        return $this->driver;
    }

    /**
     * any icons in every size, maskable (padded, opaque), monochrome, apple-touch-icon and favicon.
     *
     * @param  list<int>  $sizes
     * @return array<string, string> file => dimensions
     */
    public function icons(string $source, string $target, array $sizes, float $padding, string $background): array
    {
        $this->guard($source);
        $background = Color::hex($background);
        File::ensureDirectoryExists($target);

        $jobs = [];

        foreach ($sizes as $size) {
            $jobs["icon-{$size}.png"] = [$size, 0.0, null, false];
        }

        $jobs['maskable-512.png'] = [512, $padding, $background, false];
        $jobs['monochrome-512.png'] = [512, 0.0, null, true];
        $jobs['apple-touch-icon.png'] = [180, 0.08, $background, false]; // iOS does not support transparency
        $jobs['favicon-32.png'] = [32, 0.0, null, false];

        $written = [];

        foreach ($jobs as $file => [$size, $pad, $bg, $mono]) {
            $inner = (int) round($size * (1 - 2 * $pad));
            File::put("{$target}/{$file}", $this->driver->render($source, $size, $size, $inner, $bg, $mono));
            $written[$file] = "{$size}×{$size}";
        }

        if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'svg') {
            File::copy($source, "{$target}/icon.svg");
            $written['icon.svg'] = 'any';
        }

        return $written;
    }

    /**
     * Startup images for every iPhone and iPad screen: the icon centred on the background.
     *
     * @param  float  $iconSize  Icon edge as a fraction of the shorter screen edge.
     * @param  string|null  $dark  Background for the dark variants (null = none).
     * @param  list<string>  $orientations
     * @param  list<string>|null  $devices  Keys of AppleDevices::SCREENS (null = all)
     * @return array<string, string> file => dimensions
     */
    public function splash(
        string $source,
        string $target,
        string $background,
        ?string $dark = null,
        float $iconSize = 0.3,
        array $orientations = ['portrait', 'landscape'],
        ?array $devices = null,
    ): array {
        $this->guard($source);
        $variants = ['' => Color::hex($background)];

        if ($dark !== null) {
            $variants['-dark'] = Color::hex($dark);
        }

        File::ensureDirectoryExists($target);

        // Drop images from an earlier run so the <link> tags match what was generated now.
        foreach (glob("{$target}/apple-splash-*.png") ?: [] as $old) {
            File::delete($old);
        }

        $written = [];

        foreach (AppleDevices::screens($orientations, $devices) as $screen) {
            $inner = max(1, (int) round(min($screen['width'], $screen['height']) * $iconSize));

            foreach ($variants as $suffix => $colour) {
                $file = "{$screen['file']}{$suffix}.png";
                File::put("{$target}/{$file}", $this->driver->render($source, $screen['width'], $screen['height'], $inner, $colour));
                $written[$file] = "{$screen['width']}×{$screen['height']}";
            }
        }

        return $written;
    }

    protected function guard(string $source): void
    {
        if (! $this->driver->supports($source)) {
            throw new RuntimeException('SVG requires the imagick extension. Use a PNG or install imagick.');
        }
    }
}
