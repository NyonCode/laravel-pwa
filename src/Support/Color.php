<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Support;

use InvalidArgumentException;

/**
 * Hex colours for the image drivers. GD has no colour names, so both drivers share this.
 *
 * @internal
 */
final class Color
{
    /**
     * Normalises #rgb and #rrggbb to lowercase #rrggbb.
     *
     * @throws InvalidArgumentException
     */
    public static function hex(string $colour): string
    {
        return vsprintf('#%02x%02x%02x', self::rgb($colour));
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    public static function rgb(string $colour): array
    {
        $hex = ltrim(trim($colour), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            throw new InvalidArgumentException("Invalid colour [{$colour}], expected #rgb or #rrggbb.");
        }

        $channel = static fn (int $offset): int => max(0, min(255, (int) hexdec(substr($hex, $offset, 2))));

        return [$channel(0), $channel(2), $channel(4)];
    }
}
