<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use GdImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Imagick;
use ImagickPixel;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'pwa:icons', description: 'Vygeneruje ikony PWA (any, maskable, apple-touch-icon) z jednoho obrázku')]
class IconsCommand extends Command
{
    protected $signature = 'pwa:icons
        {source? : Zdrojový obrázek (PNG nebo SVG, ideálně 1024×1024)}
        {--background= : Barva pozadí pro maskable a iOS ikonu (výchozí theme.background)}';

    protected $description = 'Vygeneruje ikony PWA (any, maskable, apple-touch-icon) z jednoho obrázku';

    public function handle(): int
    {
        $source = (string) ($this->argument('source') ?? config('pwa.icons.source') ?? '');

        if ($source === '' || ! File::exists($source)) {
            $this->components->error('Zdrojový obrázek nenalezen. Použití: php artisan pwa:icons resources/pwa/icon.png');

            return self::FAILURE;
        }

        $background = (string) ($this->option('background') ?: config('pwa.theme.background', '#ffffff'));

        // Validate once for both drivers (Imagick would accept colour names, GD would not).
        try {
            $background = vsprintf('#%02x%02x%02x', $this->rgb($background));
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $padding = (float) config('pwa.icons.maskable_padding', 0.1);
        $target = public_path(trim((string) config('pwa.icons.path', 'pwa/icons'), '/'));
        File::ensureDirectoryExists($target);

        $isSvg = strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'svg';

        if ($isSvg && ! extension_loaded('imagick')) {
            $this->components->error('SVG vyžaduje rozšíření imagick. Použijte PNG nebo nainstalujte imagick.');

            return self::FAILURE;
        }

        if (! extension_loaded('imagick') && ! extension_loaded('gd')) {
            $this->components->error('Generování ikon vyžaduje rozšíření gd nebo imagick.');

            return self::FAILURE;
        }

        $jobs = [];

        foreach ((array) config('pwa.icons.sizes', [192, 512]) as $size) {
            $jobs["icon-{$size}.png"] = [(int) $size, 0.0, null];
        }

        $jobs['maskable-512.png'] = [512, $padding, $background];
        $jobs['apple-touch-icon.png'] = [180, 0.08, $background]; // iOS neumí průhlednost
        $jobs['favicon-32.png'] = [32, 0.0, null];

        try {
            foreach ($jobs as $file => [$size, $pad, $bg]) {
                $png = extension_loaded('imagick')
                    ? $this->renderImagick($source, $size, $pad, $bg)
                    : $this->renderGd($source, $size, $pad, $bg);

                File::put("{$target}/{$file}", $png);
                $this->components->twoColumnDetail($file, "{$size}×{$size}");
            }

            if ($isSvg) {
                File::copy($source, "{$target}/icon.svg");
                $this->components->twoColumnDetail('icon.svg', 'any');
            }
        } catch (Throwable $e) {
            $this->components->error('Generování selhalo: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Ikony uloženy do '.str_replace(base_path().'/', '', $target));

        return self::SUCCESS;
    }

    protected function renderImagick(string $source, int $size, float $padding, ?string $background): string
    {
        $image = new Imagick;
        $image->setBackgroundColor(new ImagickPixel('transparent'));
        $image->setResolution(512, 512);
        $image->readImage($source);
        $inner = (int) round($size * (1 - 2 * $padding));
        $image->thumbnailImage($inner, $inner, true, true);

        $canvas = new Imagick;
        $canvas->newImage($size, $size, new ImagickPixel($background ?? 'transparent'));
        $canvas->compositeImage($image, Imagick::COMPOSITE_OVER, intdiv($size - $image->getImageWidth(), 2), intdiv($size - $image->getImageHeight(), 2));
        $canvas->setImageFormat('png');

        return $canvas->getImageBlob();
    }

    protected function renderGd(string $source, int $size, float $padding, ?string $background): string
    {
        $src = imagecreatefromstring((string) File::get($source));

        if (! $src instanceof GdImage) {
            throw new \RuntimeException('Obrázek nejde načíst.');
        }

        if ($size < 1) {
            throw new \InvalidArgumentException("Icon size must be positive, {$size} given.");
        }

        $canvas = imagecreatetruecolor($size, $size);
        imagesavealpha($canvas, true);

        if ($background === null) {
            imagealphablending($canvas, false);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagealphablending($canvas, true);
        } else {
            [$r, $g, $b] = $this->rgb($background);
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, $r, $g, $b));
        }

        $inner = (int) round($size * (1 - 2 * $padding));
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min($inner / $w, $inner / $h);
        $dw = (int) round($w * $scale);
        $dh = (int) round($h * $scale);

        imagecopyresampled($canvas, $src, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    protected function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            throw new \InvalidArgumentException("Invalid background colour [#{$hex}], expected #rgb or #rrggbb.");
        }

        $channel = static fn (int $offset): int => max(0, min(255, (int) hexdec(substr($hex, $offset, 2))));

        return [$channel(0), $channel(2), $channel(4)];
    }
}
