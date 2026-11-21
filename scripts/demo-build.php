<?php

/*
 * Builds the static demo for GitHub Pages: php scripts/demo-build.php [output-dir]
 *
 * The package runs inside a Testbench application configured for the scope /laravel-pwa/demo/.
 * Every page and asset is rendered through the HTTP kernel and written as a file, so the demo
 * shows the real manifest, service worker, client and Blade components. docs.yml runs it before
 * the VitePress build; the output (docs/public/demo) is not committed.
 */

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use NyonCode\LaravelPwa\PwaServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$out = rtrim($argv[1] ?? $root.'/docs/public/demo', '/');
$base = '/'.trim(getenv('DEMO_BASE') ?: '/laravel-pwa/demo', '/');
$docs = getenv('DEMO_DOCS') ?: 'https://nyoncode.github.io/laravel-pwa/';
$prefix = ltrim($base, '/');

$app = Application::create(
    // After the providers registered (package config merged), before they boot (routes load).
    resolvingCallback: static fn ($app) => $app->booting(static function ($app) use ($base, $prefix, $root): void {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://nyoncode.github.io',
            'session.driver' => 'array',
            'cache.default' => 'array',
            'pwa.enabled' => true,
            'pwa.name' => 'Laravel PWA demo',
            'pwa.short_name' => 'PWA demo',
            'pwa.description' => 'A static demo of nyoncode/laravel-pwa: install, offline and updates.',
            'pwa.id' => $base.'/',
            'pwa.start_url' => $base.'/',
            'pwa.scope' => $base.'/',
            'pwa.route_prefix' => $prefix.'/pwa',
            'pwa.sw.url' => $base.'/sw.js',
            'pwa.sw.import' => [$base.'/shim.js'],
            'pwa.sw.precache_vite' => false,
            'pwa.offline.route' => $base.'/offline.html',
            'pwa.offline.precache' => [$base.'/'],
            'pwa.icons.path' => $prefix.'/icons',
            'pwa.theme' => ['light' => '#4f46e5', 'dark' => '#0b1220', 'background' => '#f8fafc'],
            'pwa.install.min_visits' => 1,
            'pwa.session.keepalive_minutes' => 0,
            'pwa.session.refresh_csrf' => false,
            'pwa.telemetry.enabled' => false,
            'pwa.push.enabled' => false,
            'pwa.cache.never_cache' => [$base.'/pwa/*'],
        ]);
        $app['view']->addNamespace('demo', $root.'/scripts/demo');
        Blade::anonymousComponentPath($root.'/scripts/demo', 'demo');
    }),
    options: ['extra' => ['providers' => [PwaServiceProvider::class]]],
);

$pages = ['index.html' => ['home', 'Home'], 'orders.html' => ['orders', 'Orders'], 'about.html' => ['about', 'About']];

foreach ($pages as $file => [$view]) {
    Route::get($prefix.'/'.($file === 'index.html' ? '' : $file), static fn () => view("demo::{$view}", ['base' => $base, 'docs' => $docs]))
        ->middleware('web');
}

app('router')->getRoutes()->refreshNameLookups();

// Icons from a generated source image (no binary file in the repository).
$source = sys_get_temp_dir().'/pwa-demo-icon.png';
$image = imagecreatetruecolor(1024, 1024);
imagefilledrectangle($image, 0, 0, 1023, 1023, (int) imagecolorallocate($image, 79, 70, 229));
$white = (int) imagecolorallocate($image, 255, 255, 255);
imagefilledrectangle($image, 300, 260, 724, 360, $white);
imagefilledrectangle($image, 300, 260, 400, 764, $white);
imagefilledrectangle($image, 624, 260, 724, 560, $white);
imagefilledrectangle($image, 300, 460, 724, 560, $white);
imagepng($image, $source);

File::deleteDirectory(public_path($prefix));
Artisan::call('pwa:icons', ['source' => $source]);

File::deleteDirectory($out);
File::ensureDirectoryExists($out);
File::copyDirectory(public_path($prefix.'/icons'), $out.'/icons');
File::copy($root.'/scripts/demo/shim.js', $out.'/shim.js');

$kernel = $app->make(Kernel::class);
$fetch = static function (string $path) use ($kernel): string {
    $response = $kernel->handle(Request::create($path, 'GET', server: ['HTTPS' => 'on', 'HTTP_HOST' => 'nyoncode.github.io']));

    if (! $response->isSuccessful()) {
        throw new RuntimeException("GET {$path} answered {$response->getStatusCode()}: ".($response->exception?->getMessage() ?? substr((string) $response->getContent(), 0, 300)));
    }

    return (string) $response->getContent();
};

// The manifest route is not under the route prefix: serve it from the demo directory.
$rewrite = static fn (string $html): string => str_replace('href="/manifest.webmanifest', 'href="'.$base.'/manifest.webmanifest', $html);

foreach (array_keys($pages) as $file) {
    File::put($out.'/'.$file, $rewrite($fetch($base.'/'.($file === 'index.html' ? '' : $file))));
}

File::put($out.'/offline.html', $fetch($base.'/offline.html'));
File::put($out.'/manifest.webmanifest', $fetch('/manifest.webmanifest'));
File::put($out.'/sw.js', $fetch($base.'/sw.js'));
File::ensureDirectoryExists($out.'/pwa');
File::put($out.'/pwa/client.js', $fetch($base.'/pwa/client.js'));
File::put($out.'/pwa/ping', $fetch($base.'/pwa/ping'));
// No server: keep-alive answers as a signed-out visitor.
File::put($out.'/pwa/session', json_encode(['csrf' => null, 'authenticated' => false, 'user' => 'guest', 'lifetime' => 120, 'warm' => []], JSON_THROW_ON_ERROR));

File::deleteDirectory(public_path($prefix));
@unlink($source);

fwrite(STDOUT, "Demo written to {$out} (".count(File::allFiles($out))." files)\n");
