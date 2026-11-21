<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Icons;

/**
 * Screen sizes of iPhones and iPads for apple-touch-startup-image.
 *
 * iOS shows a startup image only when the media query matches the device exactly, so every
 * screen size needs its own file. device-width and device-height are in CSS pixels and do not
 * swap in landscape; the file is in physical pixels.
 */
final class AppleDevices
{
    /**
     * Portrait CSS size and pixel ratio, newest first. Devices sharing a size share one entry.
     *
     * @var array<string, array{int, int, int}>
     */
    public const SCREENS = [
        'iPad Pro 13" (M4)' => [1032, 1376, 2],
        'iPad Pro 12.9"' => [1024, 1366, 2],
        'iPad Pro 11" (M4)' => [834, 1210, 2],
        'iPad Pro 11", iPad Air 11"' => [834, 1194, 2],
        'iPad Air 10.9", iPad 10.9"' => [820, 1180, 2],
        'iPad Air 10.5"' => [834, 1112, 2],
        'iPad 10.2"' => [810, 1080, 2],
        'iPad mini 8.3"' => [744, 1133, 2],
        'iPad 9.7", iPad mini 7.9"' => [768, 1024, 2],
        'iPhone 16 Pro Max, 17 Pro Max' => [440, 956, 3],
        'iPhone Air' => [420, 912, 3],
        'iPhone 16 Pro, 17, 17 Pro' => [402, 874, 3],
        'iPhone 14 Pro Max, 15 Plus, 15 Pro Max, 16 Plus' => [430, 932, 3],
        'iPhone 14 Pro, 15, 15 Pro, 16' => [393, 852, 3],
        'iPhone 12 Pro Max, 13 Pro Max, 14 Plus' => [428, 926, 3],
        'iPhone 12, 13, 14, 16e' => [390, 844, 3],
        'iPhone X, XS, 11 Pro, 12 mini, 13 mini' => [375, 812, 3],
        'iPhone XS Max, 11 Pro Max' => [414, 896, 3],
        'iPhone XR, 11' => [414, 896, 2],
        'iPhone 6 Plus – 8 Plus' => [414, 736, 3],
        'iPhone 6 – 8, SE (2nd, 3rd gen.)' => [375, 667, 2],
        'iPhone SE (1st gen.)' => [320, 568, 2],
    ];

    /**
     * @param  list<string>  $orientations  portrait and/or landscape
     * @param  list<string>|null  $devices  Keys of SCREENS (null = all)
     * @return list<array{device: string, file: string, width: int, height: int, media: string}>
     */
    public static function screens(array $orientations = ['portrait', 'landscape'], ?array $devices = null): array
    {
        $screens = [];

        foreach (self::SCREENS as $device => [$width, $height, $ratio]) {
            if ($devices !== null && ! in_array($device, $devices, true)) {
                continue;
            }

            foreach (array_intersect(['portrait', 'landscape'], $orientations) as $orientation) {
                [$pw, $ph] = $orientation === 'portrait'
                    ? [$width * $ratio, $height * $ratio]
                    : [$height * $ratio, $width * $ratio];

                $screens[] = [
                    'device' => $device,
                    'file' => "apple-splash-{$pw}x{$ph}",
                    'width' => $pw,
                    'height' => $ph,
                    'media' => "screen and (device-width: {$width}px) and (device-height: {$height}px)"
                        ." and (-webkit-device-pixel-ratio: {$ratio}) and (orientation: {$orientation})",
                ];
            }
        }

        return $screens;
    }
}
