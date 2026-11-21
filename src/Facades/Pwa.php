<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Facades;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;

/**
 * @method static bool enabled()
 * @method static bool killed()
 * @method static void disable()
 * @method static void enable()
 * @method static ManifestBuilder manifest()
 * @method static ServiceWorkerBuilder serviceWorker()
 * @method static bool isStandaloneRequest(?Request $request = null)
 * @method static bool isStandaloneClient(?Request $request = null)
 * @method static string userHash(?Request $request = null)
 * @method static string identityHash(mixed $id)
 * @method static bool pushEnabled()
 * @method static \NyonCode\LaravelPwa\Pwa warmUsing(\Closure(Request): iterable<mixed> $callback)
 * @method static list<string> warmUrls(?Request $request = null)
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
