<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static bool killed()
 * @method static void disable()
 * @method static void enable()
 * @method static \NyonCode\LaravelPwa\Manifest\ManifestBuilder manifest()
 * @method static \NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder serviceWorker()
 * @method static bool isStandaloneRequest(?\Illuminate\Http\Request $request = null)
 * @method static string userHash(?\Illuminate\Http\Request $request = null)
 * @method static array<string, mixed> clientConfig()
 * @method static array{script-src: list<string>, style-src: list<string>} cspHashes(string $view = 'pwa::maintenance')
 *
 * @see \NyonCode\LaravelPwa\Pwa
 */
class Pwa extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NyonCode\LaravelPwa\Pwa::class;
    }
}
