<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Icons;

use GdImage;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use NyonCode\LaravelPwa\Support\Color;
use RuntimeException;

class GdDriver implements ImageDriver
{
    /** @var array<string, GdImage> */
    protected array $sources = [];

    public function supports(string $source): bool
    {
        return strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'svg';
    }

    public function render(string $source, int $width, int $height, int $inner, ?string $background = null, bool $monochrome = false): string
    {
        if ($width < 1 || $height < 1 || $inner < 1) {
            throw new InvalidArgumentException("Image size must be positive, {$width}×{$height} ({$inner}) given.");
        }

        $src = $this->load($source);
        $canvas = imagecreatetruecolor($width, $height);
        imagesavealpha($canvas, true);

        if ($background === null) {
            imagealphablending($canvas, false);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagealphablending($canvas, true);
        } else {
            [$r, $g, $b] = Color::rgb($background);
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, $r, $g, $b));
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min($inner / $w, $inner / $h);
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));

        imagecopyresampled($canvas, $src, intdiv($width - $dw, 2), intdiv($height - $dh, 2), 0, 0, $dw, $dh, $w, $h);

        if ($monochrome) {
            $this->monochrome($canvas);
        }

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    protected function load(string $source): GdImage
    {
        if (isset($this->sources[$source])) {
            return $this->sources[$source];
        }

        $image = @imagecreatefromstring((string) File::get($source));

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Unable to read the image.');
        }

        return $this->sources[$source] = $image;
    }

    /**
     * Black pixels with the original alpha: the browser fills the shape with its own colour.
     */
    protected function monochrome(GdImage $canvas): void
    {
        imagealphablending($canvas, false);
        $colours = [];

        for ($x = imagesx($canvas) - 1; $x >= 0; $x--) {
            for ($y = imagesy($canvas) - 1; $y >= 0; $y--) {
                $alpha = (imagecolorat($canvas, $x, $y) >> 24) & 0x7F;
                $colours[$alpha] ??= (int) imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
                imagesetpixel($canvas, $x, $y, $colours[$alpha]);
            }
        }
    }
}
