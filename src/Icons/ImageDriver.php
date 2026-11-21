<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Icons;

/**
 * Renders the source image centred on a canvas and returns PNG bytes.
 */
interface ImageDriver
{
    /**
     * Whether the driver can read the source (GD cannot read SVG).
     */
    public function supports(string $source): bool;

    /**
     * @param  int  $inner  Edge of the box the source is scaled into (aspect ratio kept).
     * @param  string|null  $background  #rrggbb, or null for a transparent canvas.
     * @param  bool  $monochrome  Keep only the alpha channel (pixels become black), for purpose "monochrome".
     */
    public function render(string $source, int $width, int $height, int $inner, ?string $background = null, bool $monochrome = false): string;
}
