<?php

use NyonCode\LaravelPwa\Icons\GdDriver;
use NyonCode\LaravelPwa\Icons\ImageDriver;
use NyonCode\LaravelPwa\Icons\ImagickDriver;

/**
 * Both drivers must produce the same pixels, so they share the tests.
 * Each runs only where its extension is installed (CI coverage job has both).
 */
dataset('drivers', [
    'gd' => ['gd', fn (): ImageDriver => new GdDriver],
    'imagick' => ['imagick', fn (): ImageDriver => new ImagickDriver],
]);

/**
 * 64×64 PNG, transparent with an opaque red square in the middle.
 */
function driverSource(): string
{
    $image = imagecreatetruecolor(64, 64);
    imagesavealpha($image, true);
    imagealphablending($image, false);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 16, 16, 47, 47, (int) imagecolorallocate($image, 200, 30, 30));

    $path = sys_get_temp_dir().'/pwa-driver-source.png';
    imagepng($image, $path);

    return $path;
}

function pixel(string $png, int $x, int $y): array
{
    $image = imagecreatefromstring($png);

    return imagecolorsforindex($image, imagecolorat($image, $x, $y));
}

beforeEach(function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd is needed to read the result.');
    }
});

it('renders the source centred on a canvas of the requested size', function (string $ext, Closure $driver): void {
    if (! extension_loaded($ext)) {
        $this->markTestSkipped("ext-{$ext} is not installed.");
    }

    $png = $driver()->render(driverSource(), 200, 100, 80, '#00ff00');
    $info = getimagesizefromstring($png);

    expect([$info[0], $info[1], $info['mime']])->toBe([200, 100, 'image/png'])
        ->and(pixel($png, 0, 0))->toMatchArray(['red' => 0, 'green' => 255, 'blue' => 0, 'alpha' => 0])
        ->and(pixel($png, 100, 50))->toMatchArray(['red' => 200, 'green' => 30, 'blue' => 30]);
})->with('drivers');

it('keeps a transparent canvas without a background', function (string $ext, Closure $driver): void {
    if (! extension_loaded($ext)) {
        $this->markTestSkipped("ext-{$ext} is not installed.");
    }

    $png = $driver()->render(driverSource(), 64, 64, 64);

    expect(pixel($png, 2, 2)['alpha'])->toBe(127);
})->with('drivers');

it('turns the image into a black alpha mask for monochrome icons', function (string $ext, Closure $driver): void {
    if (! extension_loaded($ext)) {
        $this->markTestSkipped("ext-{$ext} is not installed.");
    }

    $png = $driver()->render(driverSource(), 64, 64, 64, null, monochrome: true);

    expect(pixel($png, 2, 2)['alpha'])->toBe(127)
        ->and(pixel($png, 32, 32))->toMatchArray(['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 0]);
})->with('drivers');

it('rejects empty sizes', function (string $ext, Closure $driver): void {
    if (! extension_loaded($ext)) {
        $this->markTestSkipped("ext-{$ext} is not installed.");
    }

    $driver()->render(driverSource(), 0, 10, 10);
})->with('drivers')->throws(InvalidArgumentException::class);

it('tells whether it can read SVG', function (): void {
    expect((new GdDriver)->supports('icon.svg'))->toBeFalse()
        ->and((new GdDriver)->supports('icon.PNG'))->toBeTrue()
        ->and((new ImagickDriver)->supports('icon.svg'))->toBeTrue();
});
