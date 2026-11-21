<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Icons;

/**
 * The <link rel="apple-touch-startup-image"> tags for the images pwa:icons --splash generated.
 */
final class SplashScreens
{
    /**
     * @return list<array{href: string, media: string}>
     */
    public static function links(string $publicPath, string $urlPath): array
    {
        $files = array_flip(array_map(basename(...), glob(rtrim($publicPath, '/').'/apple-splash-*.png') ?: []));

        if ($files === []) {
            return [];
        }

        $base = '/'.trim($urlPath, '/');
        $links = [];

        foreach (AppleDevices::screens() as $screen) {
            $light = "{$screen['file']}.png";
            $dark = "{$screen['file']}-dark.png";

            if (isset($files[$dark])) {
                $links[] = ['href' => "{$base}/{$dark}", 'media' => $screen['media'].' and (prefers-color-scheme: dark)'];
            }

            if (isset($files[$light])) {
                $links[] = [
                    'href' => "{$base}/{$light}",
                    'media' => $screen['media'].(isset($files[$dark]) ? ' and (prefers-color-scheme: light)' : ''),
                ];
            }
        }

        return $links;
    }
}
