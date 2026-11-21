<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use NyonCode\LaravelPwa\Events\TelemetryReceived;
use NyonCode\LaravelPwa\Pwa;

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::post('/login-test', fn (Request $request): array => ['remember' => $request->boolean('remember')])
            ->middleware('pwa.remember');
        Route::get('/page', fn (): string => 'page');
        Route::get('/cached-page', fn (): string => 'cached')->middleware('pwa.cache');
        Route::post('/logout-test', function (): string {
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

it('returns a fresh csrf token and anonymous user hash', function (): void {
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

it('forces remember-me in the installed app', function (array $headers, array $cookies, string $uri, bool $expected): void {
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

it('does not force remember-me from a session marked by a link', function (): void {
    $this->get('/page?source=pwa')->assertSessionHas('pwa.standalone', true);

    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/login-test')
        ->assertJson(['remember' => false]);
});

it('keeps remember-me the user explicitly turned off', function (mixed $value): void {
    $this->withHeaders(['X-PWA-Display' => 'standalone'])
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/login-test', ['remember' => $value])
        ->assertJson(['remember' => false]);
})->with(['0', 'false', 'off', false]);

it('does not force remember-me when disabled', function (): void {
    config(['pwa.session.standalone_remember' => false]);

    $this->withHeaders(['X-PWA-Display' => 'standalone'])
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/login-test')
        ->assertJson(['remember' => false]);
});

it('sends the user hash for cache separation', function (): void {
    $this->get('/page')->assertHeader('X-PWA-User', 'guest');

    $hash = $this->actingAs(testUser())->get('/page')->headers->get('X-PWA-User');

    expect($hash)->toHaveLength(16)->not->toBe('guest')->not->toContain('42');
});

it('tells the service worker about logout without clearing storage', function (): void {
    $this->actingAs(testUser())
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/logout-test')
        ->assertHeader('X-PWA-Logout', '1')
        ->assertHeader('Clear-Site-Data', '"cache"')
        ->assertHeader('X-PWA-User', 'guest');
});

it('marks pages that may be cached offline', function (): void {
    $this->get('/cached-page')->assertHeader('X-PWA-Cache', 'allow');
    $this->get('/page')->assertHeaderMissing('X-PWA-Cache');
});

it('logs telemetry from the service worker without csrf', function (): void {
    Log::shouldReceive('warning')->once()->withArgs(fn ($message, $context): bool => str_contains($message, 'precache') && $context['source'] === 'sw');

    $this->postJson('/pwa/telemetry', [
        'source' => 'sw',
        'events' => [['type' => 'precache', 'message' => 'Failed to fetch', 'version' => 'abc']],
    ])->assertStatus(202);
});

it('rejects invalid telemetry', function (): void {
    $this->postJson('/pwa/telemetry', ['source' => 'evil', 'events' => []])->assertUnprocessable();
});

it('limits every telemetry field', function (array $event, bool $valid): void {
    $response = $this->postJson('/pwa/telemetry', ['source' => 'client', 'events' => [['type' => 'error', 'message' => 'x', ...$event]]]);

    $valid ? $response->assertStatus(202) : $response->assertUnprocessable();
})->with([
    'type at 50' => [['type' => str_repeat('t', 50)], true],
    'type over 50' => [['type' => str_repeat('t', 51)], false],
    'no type' => [['type' => null], false],
    'message at 500' => [['message' => str_repeat('m', 500)], true],
    'message over 500' => [['message' => str_repeat('m', 501)], false],
    'no message' => [['message' => null], false],
    'stack over 2000' => [['stack' => str_repeat('s', 2001)], false],
    'version over 40' => [['version' => str_repeat('v', 41)], false],
    'display over 40' => [['display' => str_repeat('d', 41)], false],
    'array message' => [['message' => ['x']], false],
]);

it('accepts at most telemetry.max_events and requires events', function (): void {
    config()->set('pwa.telemetry.max_events', 2);
    $event = ['type' => 'error', 'message' => 'x'];

    $this->postJson('/pwa/telemetry', ['source' => 'sw', 'events' => [$event, $event]])->assertStatus(202)->assertJson(['logged' => 2]);
    $this->postJson('/pwa/telemetry', ['source' => 'sw', 'events' => [$event, $event, $event]])->assertUnprocessable();
    $this->postJson('/pwa/telemetry', ['source' => 'sw'])->assertUnprocessable();
    $this->postJson('/pwa/telemetry', ['events' => [$event]])->assertUnprocessable();
});

it('passes the telemetry on with the optional fields and a shortened user agent', function (): void {
    Event::fake([TelemetryReceived::class]);

    $this->withHeader('User-Agent', str_repeat('u', 400))->postJson('/pwa/telemetry', ['source' => 'client', 'events' => [
        ['type' => 'error', 'message' => 'boom', 'stack' => 'at x', 'version' => 'v1', 'display' => 'standalone'],
        ['type' => 'error', 'message' => 'bare'],
    ]])->assertStatus(202);

    Event::assertDispatched(TelemetryReceived::class, fn ($e): bool => $e->source === 'client'
        && strlen((string) $e->userAgent) === 300
        && $e->events[0] === ['type' => 'error', 'message' => 'boom', 'stack' => 'at x', 'version' => 'v1', 'display' => 'standalone']
        && $e->events[1] === ['type' => 'error', 'message' => 'bare', 'stack' => null, 'version' => null, 'display' => null]);
});

it('reports the session lifetime', function (): void {
    config()->set('session.lifetime', 77);

    $this->getJson('/pwa/session')->assertJson(['lifetime' => 77]);
});

it('treats ?source=pwa as standalone only outside security decisions', function (): void {
    $pwa = app(Pwa::class);
    $link = Request::create('/?source=pwa');
    $client = Request::create('/', server: ['HTTP_X_PWA_DISPLAY' => 'standalone']);

    expect($pwa->isStandaloneRequest($link))->toBeTrue()
        ->and($pwa->isStandaloneClient($link))->toBeFalse()
        ->and($pwa->isStandaloneClient($client))->toBeTrue();
});
