<?php

use NyonCode\LaravelPwa\Icons\AppleDevices;
use NyonCode\LaravelPwa\Icons\SplashScreens;
use NyonCode\LaravelPwa\Support\Color;

it('normalises hex colours', function (string $input, string $hex): void {
    expect(Color::hex($input))->toBe($hex);
})->with([
    ['#fff', '#ffffff'],
    ['0B1220', '#0b1220'],
    [' #AbC ', '#aabbcc'],
]);

it('rejects colour names and malformed hex', function (string $input): void {
    Color::rgb($input);
})->throws(InvalidArgumentException::class)->with(['red', '#ffff', '#gggggg', '']);

it('lists every Apple screen once per orientation with physical pixel sizes', function (): void {
    $screens = AppleDevices::screens();

    expect($screens)->toHaveCount(count(AppleDevices::SCREENS) * 2)
        ->and(array_unique(array_column($screens, 'file')))->toHaveCount(count($screens));

    $portrait = collect($screens)->firstWhere('file', 'apple-splash-1320x2868');
    $landscape = collect($screens)->firstWhere('file', 'apple-splash-2868x1320');

    expect($portrait['media'])->toBe('screen and (device-width: 440px) and (device-height: 956px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)')
        // device-width and device-height do not swap in landscape on iOS
        ->and($landscape['media'])->toContain('(device-width: 440px) and (device-height: 956px)')
        ->and($landscape['media'])->toEndWith('(orientation: landscape)');
});

it('filters screens by orientation and device', function (): void {
    $screens = AppleDevices::screens(['portrait'], ['iPhone SE (1st gen.)']);

    expect($screens)->toHaveCount(1)
        ->and($screens[0])->toMatchArray(['file' => 'apple-splash-640x1136', 'width' => 640, 'height' => 1136]);
});

it('links only the splash images that exist, dark ones first', function (): void {
    $dir = sys_get_temp_dir().'/pwa-splash-'.uniqid();
    mkdir($dir);
    touch("{$dir}/apple-splash-640x1136.png");
    touch("{$dir}/apple-splash-640x1136-dark.png");
    touch("{$dir}/apple-splash-1136x640.png");

    $links = SplashScreens::links($dir, 'pwa/icons/splash');

    expect($links)->toHaveCount(3)
        ->and($links[0]['href'])->toBe('/pwa/icons/splash/apple-splash-640x1136-dark.png')
        ->and($links[0]['media'])->toEndWith('(orientation: portrait) and (prefers-color-scheme: dark)')
        ->and($links[1]['media'])->toEndWith('and (prefers-color-scheme: light)')
        ->and($links[2]['media'])->toEndWith('(orientation: landscape)');

    array_map(unlink(...), glob("{$dir}/*"));
    rmdir($dir);

    expect(SplashScreens::links($dir, 'x'))->toBe([]);
});
