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

    public static function bool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // filter_var() reads null as false; a missing setting keeps its default.
        return $value === null ? $default : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    /**
     * An integer setting. Accepts env() strings ("120") that config()->integer() rejects:
     * application configs often skip the (int) cast (e.g. 'lifetime' => env('SESSION_LIFETIME', 120)).
     */
    public static function configInt(string $key, int $default = 0): int
    {
        return self::int(config($key), $default);
    }

    /**
     * A boolean setting. Accepts env() strings ("true", "0") that config()->boolean() rejects.
     */
    public static function configBool(string $key, bool $default = false): bool
    {
        return self::bool(config($key), $default);
    }
}
