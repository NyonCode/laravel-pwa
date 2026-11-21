<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Testing;

use Illuminate\Foundation\Testing\TestCase;
use NyonCode\LaravelPwa\Pwa;

/**
 * Request helpers for application tests. Use in tests/TestCase.php:
 *
 *     use NyonCode\LaravelPwa\Testing\InteractsWithPwa;
 *
 *     $this->asInstalledApp()->post('/login', $credentials)->assertCookie(Auth::guard()->getRecallerName());
 *
 * @mixin TestCase
 */
trait InteractsWithPwa
{
    /**
     * Requests as the installed app sends them: the display-mode header and cookie of the client.
     *
     * @param  'standalone'|'minimal-ui'|'window-controls-overlay'|'fullscreen'|'tabbed'  $mode
     */
    public function asInstalledApp(string $mode = 'standalone'): static
    {
        return $this->withHeader(Pwa::DISPLAY_HEADER, $mode)->withUnencryptedCookie(Pwa::DISPLAY_COOKIE, $mode);
    }

    /**
     * Requests from a normal browser tab (the client reports `browser`).
     */
    public function asBrowserTab(): static
    {
        return $this->withHeader(Pwa::DISPLAY_HEADER, 'browser')->withUnencryptedCookie(Pwa::DISPLAY_COOKIE, 'browser');
    }
}
