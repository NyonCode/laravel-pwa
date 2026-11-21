<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NyonCode\LaravelPwa\Manifest\DisplayStrategy;
use NyonCode\LaravelPwa\Pwa;

/**
 * Manifest, service worker and client script.
 *
 * No session middleware: no cookies, the responses are the same for everyone
 * (except the adaptive manifest, which sends Vary).
 */
class AssetController
{
    public function manifest(Request $request, Pwa $pwa): Response
    {
        $manifest = $pwa->manifest()->forRequest($request);
        $data = $manifest->toArray($request);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $strategy = DisplayStrategy::fromConfig();
        $policy = $manifest->cachePolicy();

        $vary = $policy['vary'];

        if ($strategy->varies()) {
            $vary = [...$vary, 'Sec-CH-UA-Platform', 'User-Agent'];
        }

        $headers = [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => $policy['public'] && ! $strategy->varies() ? 'public, max-age=3600' : 'private, max-age=3600',
            'ETag' => '"'.substr(hash('sha256', $json), 0, 16).'"',
        ];

        if (is_string($data['lang'] ?? null)) {
            $headers['Content-Language'] = $data['lang'];
        }

        if ($vary !== []) {
            $headers['Vary'] = implode(', ', array_values(array_unique($vary)));
        }

        if ($strategy->varies()) {
            $headers['Accept-CH'] = 'Sec-CH-UA-Platform';
        }

        return $this->conditional($request, $json, $headers);
    }

    public function serviceWorker(Request $request, Pwa $pwa): Response
    {
        $builder = $pwa->serviceWorker();

        // Kill switch or disabled locally: a SW that removes itself.
        $script = $pwa->enabled() ? $builder->contents() : $builder->killSwitch();

        return $this->conditional($request, $script, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, max-age=0',
            'Service-Worker-Allowed' => config()->string('pwa.scope', '/'),
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
