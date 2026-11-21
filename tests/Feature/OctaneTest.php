<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use NyonCode\LaravelPwa\Facades\Pwa;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;
use NyonCode\LaravelPwa\Push\WebPush;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;

// Octane keeps the application and its singletons alive between requests. The package's
// singletons are configured once (boot) and must never keep anything from one request for the next.

function octaneUser(int $id): User
{
    return (new User)->forceFill(['id' => $id, 'name' => "User {$id}"]);
}

it('keeps no request state in its singletons', function (): void {
    $singletons = [NyonCode\LaravelPwa\Pwa::class, ManifestBuilder::class, ServiceWorkerBuilder::class, WebPush::class];
    $before = array_map(static fn (string $class): string => serialize(array_map(
        static fn (ReflectionProperty $property): mixed => is_object($value = $property->getValue(app($class))) ? $value::class : $value,
        array_filter((new ReflectionClass(app($class)))->getProperties(), static fn (ReflectionProperty $property): bool => ! $property->isStatic()),
    )), $singletons);

    Pwa::manifest()->resolveUsing(fn (Request $request, ManifestBuilder $manifest): ManifestBuilder => $manifest->name('Tenant '.$request->query('t')));
    $this->get('/manifest.webmanifest?t=a')->assertJsonPath('name', 'Tenant a');
    $this->actingAs(octaneUser(1))->getJson('/pwa/session');
    $this->actingAs(octaneUser(2))->getJson('/pwa/session');
    Pwa::manifest()->flush();

    $after = array_map(static fn (string $class): string => serialize(array_map(
        static fn (ReflectionProperty $property): mixed => is_object($value = $property->getValue(app($class))) ? $value::class : $value,
        array_filter((new ReflectionClass(app($class)))->getProperties(), static fn (ReflectionProperty $property): bool => ! $property->isStatic()),
    )), $singletons);

    expect($after)->toBe($before);
});

it('answers each request for its own user', function (): void {
    Pwa::warmUsing(fn (Request $request): array => ['/orders/'.$request->user()?->getAuthIdentifier()]);

    $first = $this->actingAs(octaneUser(1))->getJson('/pwa/session')->json();
    Auth::forgetGuards();
    $second = $this->actingAs(octaneUser(2))->getJson('/pwa/session')->json();

    expect($first['user'])->not->toBe($second['user'])
        ->and($first['warm'])->toBe(['/orders/1'])
        ->and($second['warm'])->toBe(['/orders/2']);
});

it('builds the manifest of each request on a fresh copy', function (): void {
    Pwa::manifest()->resolveUsing(fn (Request $request, ManifestBuilder $manifest): ?ManifestBuilder => $request->query('brand') ? $manifest->name('Brand')->themeColor('#ff0000') : null);

    $this->get('/manifest.webmanifest?brand=1')->assertJsonPath('name', 'Brand');
    $plain = $this->get('/manifest.webmanifest')->json();

    expect($plain['name'])->not->toBe('Brand')
        ->and($plain['theme_color'])->not->toBe('#ff0000');

    Pwa::manifest()->flush();
});
