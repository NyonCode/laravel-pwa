<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use NyonCode\LaravelPwa\Icons\IconGenerator;
use NyonCode\LaravelPwa\Support\Color;
use NyonCode\LaravelPwa\Support\Value;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'pwa:icons', description: 'Generate the PWA icons (any, maskable, monochrome, apple-touch-icon) and iOS splash screens from a single image')]
class IconsCommand extends Command
{
    protected $signature = 'pwa:icons
        {source? : The source image (PNG or SVG, ideally 1024×1024)}
        {--background= : Background colour for the maskable and iOS icons (defaults to theme.background)}
        {--splash : Also generate iOS startup images (apple-touch-startup-image) for every iPhone and iPad}';

    protected $description = 'Generate the PWA icons (any, maskable, monochrome, apple-touch-icon) and iOS splash screens from a single image';

    public function handle(): int
    {
        $source = Value::string($this->argument('source') ?? config('pwa.icons.source'));

        if ($source === '' || ! File::exists($source)) {
            $this->components->error('Source image not found. Usage: php artisan pwa:icons resources/pwa/icon.png');

            return self::FAILURE;
        }

        $background = Value::string($this->option('background') ?: config('pwa.theme.background', '#ffffff'), '#ffffff');
        $splash = (array) config('pwa.icons.splash', []);
        $splashBackground = Value::string($splash['background'] ?? null) ?: $background;
        $splashDark = Value::nullableString($splash['dark_background'] ?? null) ?: null;

        // Validate up front for both drivers (Imagick would accept colour names, GD would not).
        try {
            array_map(Color::hex(...), array_filter([$background, $splashBackground, $splashDark]));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $configured = config('pwa.icons.maskable_padding', 0.1);
        $padding = is_numeric($configured) ? (float) $configured : 0.1;
        $sizes = array_values(array_filter(array_map(Value::int(...), (array) config('pwa.icons.sizes', [192, 512])), fn (int $size): bool => $size > 0));
        $target = public_path(trim(config()->string('pwa.icons.path', 'pwa/icons'), '/'));

        try {
            $generator = IconGenerator::for($source);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            foreach ($generator->icons($source, $target, $sizes, $padding, $background) as $file => $size) {
                $this->components->twoColumnDetail($file, $size);
            }

            if ($this->option('splash')) {
                $iconSize = is_numeric($splash['icon_size'] ?? null) ? (float) $splash['icon_size'] : 0.3;
                $orientations = array_values(array_map(Value::string(...), (array) ($splash['orientations'] ?? ['portrait', 'landscape'])));
                $devices = isset($splash['devices']) ? array_values(array_map(Value::string(...), (array) $splash['devices'])) : null;

                $written = $generator->splash($source, "{$target}/splash", $splashBackground, $splashDark, $iconSize, $orientations, $devices);
                $this->components->twoColumnDetail('splash/apple-splash-*.png', count($written).' images');
            }
        } catch (Throwable $e) {
            $this->components->error('Icon generation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Icons saved to '.str_replace(base_path().'/', '', $target));

        return self::SUCCESS;
    }
}
