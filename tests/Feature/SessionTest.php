<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use NyonCode\LaravelPwa\Pwa;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::post('/login-test', fn (Request $request) => ['remember' => $request->boolean('remember')])
            ->middleware('pwa.remember');
        Route::get('/page', fn () => 'page');
        Route::get('/cached-page', fn () => 'cached')->middleware('pwa.cache');
        Route::post('/logout-test', function () {
            Auth::guard('web')->logout();

            return 'bye';
        });
    });
});

function testUser(): User
{
    $user = new User;
    $user->forceFill(['id' => 42, 'name' => 'Ondřej', 'email' => 'o@example.cz']);

    return $user;
}

it('returns a fresh csrf token and anonymous user hash', function () {
    $this->getJson('/pwa/session')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['authenticated' => false, 'user' => 'guest'])
        ->assertJsonStructure(['csrf', 'lifetime']);

    $this->actingAs(testUser())
        ->getJson('/pwa/session')
        ->assertJson(['authenticated' => true])
        ->assertJsonMissing(['user' => '42']);
});

it('forces remember-me in the installed app', function (array $headers, array $cookies, string $uri, bool $expected) {
    $response = $this->withHeaders($headers)
        ->withUnencryptedCookies($cookies)
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->post($uri);

    expect($response->json('remember'))->toBe($expected);
})->with([
    'hlavička standalone' => [['X-PWA-Display' => 'standalone'], [], '/login-test', true],
    'hlavička minimal-ui (Edge desktop)' => [['X-PWA-Display' => 'minimal-ui'], [], '/login-test', true],
    'cookie z klienta' => [[], ['pwa_display' => 'standalone'], '/login-test', true],
    'jen ?source=pwa (dá se podstrčit odkazem)' => [[], [], '/login-test?source=pwa', false],
    'běžný prohlížeč' => [['X-PWA-Display' => 'browser'], [], '/login-test', false],
    'nic' => [[], [], '/login-test', false],
]);

it('does not force remember-me from a session marked by a link', function () {
    $this->get('/page?source=pwa')->assertSessionHas('pwa.standalone', true);

    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/login-test')
        ->assertJson(['remember' => false]);
});

it('keeps remember-me the user explicitly turned off', function (mixed $value) {
    $this->withHeaders(['X-PWA-Display' => 'standalone'])
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/login-test', ['remember' => $value])
        ->assertJson(['remember' => false]);
})->with(['0', 'false', 'off', false]);

it('does not force remember-me when disabled', function () {
    config(['pwa.session.standalone_remember' => false]);

    $this->withHeaders(['X-PWA-Display' => 'standalone'])
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/login-test')
        ->assertJson(['remember' => false]);
});

it('sends the user hash for cache separation', function () {
    $this->get('/page')->assertHeader('X-PWA-User', 'guest');

    $hash = $this->actingAs(testUser())->get('/page')->headers->get('X-PWA-User');

    expect($hash)->toHaveLength(16)->not->toBe('guest')->not->toContain('42');
});

it('tells the service worker about logout without clearing storage', function () {
    $this->actingAs(testUser())
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/logout-test')
        ->assertHeader('X-PWA-Logout', '1')
        ->assertHeader('Clear-Site-Data', '"cache"')
        ->assertHeader('X-PWA-User', 'guest');
});

it('marks pages that may be cached offline', function () {
    $this->get('/cached-page')->assertHeader('X-PWA-Cache', 'allow');
    $this->get('/page')->assertHeaderMissing('X-PWA-Cache');
});

it('logs telemetry from the service worker without csrf', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'precache') && $context['source'] === 'sw');

    $this->postJson('/pwa/telemetry', [
        'source' => 'sw',
        'events' => [['type' => 'precache', 'message' => 'Failed to fetch', 'version' => 'abc']],
    ])->assertStatus(202);
});

it('rejects invalid telemetry', function () {
    $this->postJson('/pwa/telemetry', ['source' => 'evil', 'events' => []])->assertUnprocessable();
});

it('treats ?source=pwa as standalone only outside security decisions', function () {
    $pwa = app(Pwa::class);
    $link = Request::create('/?source=pwa');
    $client = Request::create('/', server: ['HTTP_X_PWA_DISPLAY' => 'standalone']);

    expect($pwa->isStandaloneRequest($link))->toBeTrue()
        ->and($pwa->isStandaloneClient($link))->toBeFalse()
        ->and($pwa->isStandaloneClient($client))->toBeTrue();
});
