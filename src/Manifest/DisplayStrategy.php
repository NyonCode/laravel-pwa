<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Manifest;

use Illuminate\Http\Request;

/**
 * Rozhoduje, jaký režim zobrazení dostane manifest.
 */
enum DisplayStrategy: string
{
    /** display + display_override, bez detekce zařízení (výchozí). */
    case Override = 'override';

    /** Server rozhodne podle platformy (Sec-CH-UA-Platform / User-Agent). */
    case Adaptive = 'adaptive';

    /** Jen display. */
    case Fixed = 'fixed';

    public static function fromConfig(): self
    {
        return self::tryFrom((string) config('pwa.display.strategy', 'override')) ?? self::Override;
    }

    /**
     * @return array{display: string, display_override?: list<string>}
     */
    public function resolve(?Request $request = null): array
    {
        $value = (string) config('pwa.display.value', 'standalone');

        return match ($this) {
            self::Fixed => ['display' => $value],
            self::Override => ['display' => $value, ...$this->overrideModes()],
            self::Adaptive => [
                'display' => self::isDesktop($request ?? request())
                    ? (string) config('pwa.display.desktop', 'minimal-ui')
                    : $value,
            ],
        };
    }

    /**
     * Desktop = Windows, macOS, Linux, ChromeOS. Safari se hlásí vždy jako Mac
     * (iPad také), proto bez Client Hints rozhoduje jen Windows / Linux / CrOS.
     */
    public static function isDesktop(Request $request): bool
    {
        $platform = trim((string) $request->header('Sec-CH-UA-Platform', ''), '"');

        if ($platform !== '') {
            return in_array($platform, ['Windows', 'macOS', 'Linux', 'Chrome OS'], true);
        }

        return (bool) preg_match('/Windows NT|X11; Linux|CrOS/', (string) $request->userAgent());
    }

    /**
     * @return array{display_override?: list<string>}
     */
    protected function overrideModes(): array
    {
        $modes = array_values(array_map(strval(...), (array) config('pwa.display.override', [])));

        return $modes === [] ? [] : ['display_override' => $modes];
    }

    public function varies(): bool
    {
        return $this === self::Adaptive;
    }
}
