<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Support;

use Stringable;

/**
 * Narrows untyped values (request input, decoded JSON, facade results) to scalars.
 *
 * Unlike config()->string() these never throw: they are for data the package
 * does not control, where a wrong type falls back to the default.
 *
 * @internal
 */
final class Value
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
