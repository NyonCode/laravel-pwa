<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NyonCode\LaravelPwa\Manifest\DisplayStrategy;
use NyonCode\LaravelPwa\Pwa;

/**
 * Manifest, service worker a klientský skript.
 *
 * Bez session middleware: žádné cookies, odpovědi jsou pro všechny stejné
 * (kromě adaptivního manifestu, který posílá Vary).
 */
class AssetController
{
    public function manifest(Request $request, Pwa $pwa): Response
    {
        $json = $pwa->manifest()->toJson($request);
        $strategy = DisplayStrategy::fromConfig();

        $headers = [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => $strategy->varies() ? 'private, max-age=3600' : 'public, max-age=3600',
            'ETag' => '"'.substr(hash('sha256', $json), 0, 16).'"',
        ];

        if ($strategy->varies()) {
            $headers['Vary'] = 'Sec-CH-UA-Platform, User-Agent';
            $headers['Accept-CH'] = 'Sec-CH-UA-Platform';
        }

        return $this->conditional($request, $json, $headers);
    }

    public function serviceWorker(Request $request, Pwa $pwa): Response
    {
        $builder = $pwa->serviceWorker();

        // Nouzové vypnutí i lokální vypnutí: SW, který se sám odstraní.
        $script = $pwa->enabled() ? $builder->contents() : $builder->killSwitch();

        return $this->conditional($request, $script, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, max-age=0',
            'Service-Worker-Allowed' => (string) config('pwa.scope', '/'),
            'ETag' => '"'.substr(hash('sha256', $script), 0, 16).'"',
        ]);
    }

    public function client(Request $request): Response
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/pwa.js');

        return $this->conditional($request, $script, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.substr(hash('sha256', $script), 0, 16).'"',
        ]);
    }

    public function ping(): Response
    {
        return response('', 204, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function conditional(Request $request, string $body, array $headers): Response
    {
        $etag = $headers['ETag'] ?? null;

        if ($etag !== null && in_array($etag, $request->getETags(), true)) {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers);
    }
}
