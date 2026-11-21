<?php

use Illuminate\Support\Facades\File;
use NyonCode\LaravelPwa\Pwa;

/**
 * Writes a square PNG with a transparent border and returns its path.
 */
function sourceIcon(int $size = 64): string
{
    $image = imagecreatetruecolor($size, $size);
    imagesavealpha($image, true);
    imagealphablending($image, false);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 8, 8, $size - 9, $size - 9, (int) imagecolorallocate($image, 200, 30, 30));

    File::ensureDirectoryExists(storage_path('framework/testing'));
    $path = storage_path('framework/testing/icon-source.png');
    imagepng($image, $path);

    return $path;
}

describe('pwa:icons', function (): void {
    beforeEach(function (): void {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required.');
        }
    });

    it('generates any, maskable and apple-touch icons', function (): void {
        config(['pwa.icons.sizes' => [192, 512]]);

        $this->artisan('pwa:icons', ['source' => sourceIcon()])->assertSuccessful();

        foreach (['icon-192.png' => 192, 'icon-512.png' => 512, 'maskable-512.png' => 512, 'apple-touch-icon.png' => 180] as $file => $size) {
            $info = getimagesize(public_path("pwa/icons/{$file}"));
            expect($info)->not->toBeFalse()
                ->and([$info[0], $info[1], $info['mime']])->toBe([$size, $size, 'image/png']);
        }
    });

    it('keeps any icons transparent and fills maskable and iOS icons', function (): void {
        $this->artisan('pwa:icons', ['source' => sourceIcon(), '--background' => '#00ff00'])->assertSuccessful();

        $any = imagecreatefrompng(public_path('pwa/icons/icon-192.png'));
        $maskable = imagecreatefrompng(public_path('pwa/icons/maskable-512.png'));
        $apple = imagecreatefrompng(public_path('pwa/icons/apple-touch-icon.png'));

        expect(imagecolorsforindex($any, imagecolorat($any, 0, 0))['alpha'])->toBe(127)
            ->and(imagecolorsforindex($maskable, imagecolorat($maskable, 0, 0)))->toMatchArray(['red' => 0, 'green' => 255, 'blue' => 0, 'alpha' => 0])
            ->and(imagecolorsforindex($apple, imagecolorat($apple, 0, 0)))->toMatchArray(['green' => 255, 'alpha' => 0]);
    });

    it('uses the configured source and accepts short hex colours', function (): void {
        config(['pwa.icons.source' => sourceIcon(), 'pwa.theme.background' => '#fff']);

        $this->artisan('pwa:icons')->assertSuccessful();

        expect(File::exists(public_path('pwa/icons/maskable-512.png')))->toBeTrue();
    });

    it('rejects an invalid background colour', function (): void {
        $this->artisan('pwa:icons', ['source' => sourceIcon(), '--background' => 'red'])->assertFailed();
    });

    it('fails without a source image', function (): void {
        $this->artisan('pwa:icons', ['source' => '/missing.png'])
            ->expectsOutputToContain('pwa:icons resources/pwa/icon.png')
            ->assertFailed();
    });

    it('needs imagick for SVG sources', function (): void {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('Only meaningful without ext-imagick.');
        }

        $svg = storage_path('framework/testing/icon.svg');
        File::ensureDirectoryExists(dirname($svg));
        File::put($svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>');

        $this->artisan('pwa:icons', ['source' => $svg])->expectsOutputToContain('imagick')->assertFailed();
    });
});

describe('pwa:build', function (): void {
    it('warns when the Vite manifest is missing', function (): void {
        $this->artisan('pwa:build')
            ->expectsOutputToContain('npm run build')
            ->assertSuccessful();
    });

    it('fails on an invalid Vite manifest', function (): void {
        File::ensureDirectoryExists(public_path('build'));
        File::put(public_path('build/manifest.json'), '{not json');

        $this->artisan('pwa:build')->assertFailed();
    });
});

describe('pwa:disable', function (): void {
    it('can be cancelled', function (): void {
        $this->artisan('pwa:disable')
            ->expectsConfirmation('Are you sure you want to disable the service worker? Devices will clear the cache and unregister the SW on their next visit.', 'no')
            ->assertFailed();

        expect(app(Pwa::class)->killed())->toBeFalse();
    });

    it('warns about push subscriptions and a non-shared cache store', function (): void {
        config(['pwa.sw.import' => ['/push-sw.js'], 'cache.default' => 'array']);

        $this->artisan('pwa:disable', ['--force' => true])
            ->expectsOutputToContain('push')
            ->expectsOutputToContain('PWA_KILL_SWITCH_STORE')
            ->assertSuccessful();

        app(Pwa::class)->enable();
    });
});
