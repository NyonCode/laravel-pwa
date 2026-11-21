<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Manifest;

use Illuminate\Http\Request;

/**
 * Decides which display mode the manifest gets.
 */
enum DisplayStrategy: string
{
    /** display + display_override, no device detection (default). */
    case Override = 'override';

    /** The server decides by platform (Sec-CH-UA-Platform / User-Agent). */
    case Adaptive = 'adaptive';

    /** Only display. */
    case Fixed = 'fixed';

    public static function fromConfig(): self
    {
        $strategy = config('pwa.display.strategy', 'override');

        return (is_string($strategy) ? self::tryFrom($strategy) : null) ?? self::Override;
    }

    /**
     * @return array{display: string, display_override?: list<string>}
     */
    public function resolve(?Request $request = null): array
    {
        $value = config()->string('pwa.display.value', 'standalone');

        return match ($this) {
            self::Fixed => ['display' => $value],
            self::Override => ['display' => $value, ...$this->overrideModes()],
            self::Adaptive => [
                'display' => self::isDesktop($request ?? request())
                    ? config()->string('pwa.display.desktop', 'minimal-ui')
                    : $value,
            ],
        };
    }

    /**
     * Desktop = Windows, macOS, Linux, ChromeOS. Safari always reports itself as a Mac
     * (the iPad too), so without Client Hints only Windows / Linux / CrOS are detected.
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
        $modes = array_values(array_filter(config()->array('pwa.display.override', []), is_string(...)));

        return $modes === [] ? [] : ['display_override' => $modes];
    }

    public function varies(): bool
    {
        return $this === self::Adaptive;
    }
}
