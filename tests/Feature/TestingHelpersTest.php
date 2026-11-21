<?php

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use NyonCode\LaravelPwa\Manifest\ManifestValidator;
use NyonCode\LaravelPwa\Testing\InteractsWithPwa;
use PHPUnit\Framework\AssertionFailedError;

uses(InteractsWithPwa::class);

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::get('/offline-ready', fn (): string => 'ok')->middleware('pwa.cache');
        Route::get('/online-only', fn (): string => 'ok');
        Route::get('/no-store', fn (): ResponseFactory|Response => response('ok', 200, ['Cache-Control' => 'no-store']))->middleware('pwa.cache');
        Route::post('/login-helper', fn (Request $request): array => ['remember' => $request->boolean('remember')])->middleware('pwa.remember');
        Route::post('/logout-helper', function (): string {
            Auth::guard('web')->logout();

            return 'bye';
        });
    });
});

function helperUser(int $id = 7): User
{
    return (new User)->forceFill(['id' => $id, 'name' => 'Test', 'email' => "u{$id}@example.com"]);
}

it('sends the installed-app signals with asInstalledApp()', function (): void {
    $this->asInstalledApp()->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/login-helper')->assertJson(['remember' => true]);

    $this->asBrowserTab()->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/login-helper')->assertJson(['remember' => false]);
});

it('asserts pages allowed offline', function (): void {
    $this->get('/offline-ready')->assertPwaCacheable();
    $this->get('/online-only')->assertNotPwaCacheable();

    expect(fn () => $this->get('/online-only')->assertPwaCacheable())->toThrow(AssertionFailedError::class, 'add the pwa.cache middleware');
    expect(fn () => $this->get('/no-store')->assertPwaCacheable())->toThrow(AssertionFailedError::class, 'no-store');
    expect(fn () => $this->get('/offline-ready')->assertNotPwaCacheable())->toThrow(AssertionFailedError::class);
});

it('asserts the logout signal for the worker', function (): void {
    $this->actingAs(helperUser())->withoutMiddleware(ValidateCsrfToken::class)->post('/logout-helper')->assertPwaLogout();

    expect(fn () => $this->get('/online-only')->assertPwaLogout())->toThrow(AssertionFailedError::class, 'X-PWA-Logout');
});

it('asserts the worker identity of the user', function (): void {
    $this->get('/online-only')->assertPwaUser(null)->assertPwaUser('guest');
    $this->actingAs(helperUser())->get('/online-only')->assertPwaUser(helperUser());

    expect(fn () => $this->actingAs(helperUser())->get('/online-only')->assertPwaUser(helperUser(8)))
        ->toThrow(AssertionFailedError::class, 'X-PWA-User');
});

it('asserts an installable manifest', function (): void {
    File::ensureDirectoryExists(public_path('pwa/icons'));
    foreach (['icon-192.png', 'icon-512.png', 'maskable-512.png'] as $file) {
        File::put(public_path("pwa/icons/{$file}"), 'x');
    }
    config(['pwa.short_name' => 'Tests']);

    $this->get('/manifest.webmanifest')->assertValidManifest()->assertValidManifest(strict: true);
});

it('fails the manifest assertion with the reasons', function (): void {
    expect(fn () => $this->get('/manifest.webmanifest')->assertValidManifest())
        ->toThrow(AssertionFailedError::class, 'No 192x192 icon');
    expect(fn () => $this->get('/pwa/ping')->assertValidManifest())
        ->toThrow(AssertionFailedError::class, 'not a JSON manifest');
});

it('validates manifests', function (array $manifest, array $errors, array $warnings): void {
    $base = ['name' => 'App', 'start_url' => '/', 'display' => 'standalone', 'id' => '/', 'icons' => [
        ['src' => '/a.png', 'sizes' => '192x192 512x512', 'purpose' => 'any'],
        ['src' => '/m.png', 'sizes' => '512x512', 'purpose' => 'maskable'],
    ]];

    $result = app(ManifestValidator::class)->validate([...$base, ...$manifest]);

    expect($result['errors'])->toHaveCount(count($errors))
        ->and(implode(' ', $result['errors']))->toContain(...($errors ?: ['']))
        ->and(implode(' ', $result['warnings']))->toContain(...($warnings ?: ['']));
})->with([
    'valid' => [[], [], []],
    'no name' => [['name' => ''], ['Missing [name]'], []],
    'bad display' => [['display' => 'window'], ['[display] must be one of'], []],
    'svg any icon counts for every size' => [['icons' => [['src' => '/i.svg', 'sizes' => 'any']]], [], ['No maskable icon']],
    'only maskable icons' => [['icons' => [['src' => '/m.png', 'sizes' => '192x192 512x512', 'purpose' => 'maskable']]], ['No 192x192 icon', 'No 512x512 icon'], []],
    'long short_name and bad colour' => [['short_name' => 'A very long app name', 'theme_color' => 'blue'], [], ['[short_name] is longer', '[theme_color] should be']],
    'no id' => [['id' => null], [], ['No [id]']],
]);
