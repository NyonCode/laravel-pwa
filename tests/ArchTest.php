<?php

arch('it does not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

arch('contracts are interfaces')
    ->expect('NyonCode\LaravelPwa\Contracts')
    ->toBeInterfaces();

arch('events are final')
    ->expect('NyonCode\LaravelPwa\Events')
    ->toBeFinal()
    ->toUseTrait(Illuminate\Foundation\Events\Dispatchable::class);

arch('the source is strictly typed')
    ->expect('NyonCode\LaravelPwa')
    ->toUseStrictTypes();

// Everything outside the public API (docs: Versioning) is marked @internal, so a change there is
// never a breaking change. A new class in these namespaces must be marked too.
it('marks classes outside the public API as @internal', function (): void {
    $internal = [
        ...glob(dirname(__DIR__).'/src/{Http/Controllers,Http/Requests,Listeners,Console,Support}/*.php', GLOB_BRACE),
        ...array_map(static fn (string $file): string => dirname(__DIR__).'/src/'.$file, [
            'Icons/GdDriver.php', 'Icons/ImagickDriver.php', 'Icons/ImageDriver.php', 'Icons/IconGenerator.php',
            'Icons/SplashScreens.php', 'Manifest/LocaleResolver.php', 'Push/MinishlinkTransport.php',
            'Push/SendWebPush.php', 'Push/Vapid.php', 'Tenancy/TenantManifestResolver.php',
        ]),
    ];

    $missing = array_filter($internal, static fn (string $file): bool => ! preg_match('#@internal\b[^\n]*\n(?:\s*\*[^\n]*\n)*\s*\*/\s*\n(?:\#\[[^\n]*\n)*(?:final |abstract |readonly )*(?:class|interface|trait|enum) #', (string) file_get_contents($file)));

    expect(array_map('basename', array_values($missing)))->toBe([]);
});
