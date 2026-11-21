<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Icons;

use Imagick;
use ImagickPixel;
use InvalidArgumentException;

/**
 * @internal
 */
class ImagickDriver implements ImageDriver
{
    /** @var array<string, Imagick> */
    protected array $sources = [];

    public function supports(string $source): bool
    {
        return true;
    }

    public function render(string $source, int $width, int $height, int $inner, ?string $background = null, bool $monochrome = false): string
    {
        if ($width < 1 || $height < 1 || $inner < 1) {
            throw new InvalidArgumentException("Image size must be positive, {$width}×{$height} ({$inner}) given.");
        }

        $image = clone $this->load($source);
        $image->thumbnailImage($inner, $inner, true, true);

        $canvas = new Imagick;
        $canvas->newImage($width, $height, new ImagickPixel($background ?? 'transparent'));
        $canvas->compositeImage($image, Imagick::COMPOSITE_OVER, intdiv($width - $image->getImageWidth(), 2), intdiv($height - $image->getImageHeight(), 2));

        if ($monochrome) {
            foreach ([Imagick::CHANNEL_RED, Imagick::CHANNEL_GREEN, Imagick::CHANNEL_BLUE] as $channel) {
                $canvas->evaluateImage(Imagick::EVALUATE_SET, 0, $channel);
            }
        }

        $canvas->setImageFormat('png');

        return $canvas->getImageBlob();
    }

    protected function load(string $source): Imagick
    {
        if (isset($this->sources[$source])) {
            return $this->sources[$source];
        }

        $image = new Imagick;
        $image->setBackgroundColor(new ImagickPixel('transparent'));
        $image->setResolution(512, 512);   // rasterise SVG sharply
        $image->readImage($source);

        return $this->sources[$source] = $image;
    }
}
