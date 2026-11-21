<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NyonCode\LaravelPwa\Events\KillSwitchActivated;
use NyonCode\LaravelPwa\Events\KillSwitchDeactivated;
use NyonCode\LaravelPwa\Events\ManifestResolving;
use NyonCode\LaravelPwa\Events\ServiceWorkerBuilt;
use NyonCode\LaravelPwa\Events\TelemetryReceived;
use NyonCode\LaravelPwa\Facades\Pwa;
use NyonCode\LaravelPwa\Listeners\LogTelemetry;
use Psr\Log\LoggerInterface;

afterEach(fn () => Pwa::manifest()->flush());

it('dispatches ServiceWorkerBuilt with the version, path and precache', function (): void {
    Event::fake([ServiceWorkerBuilt::class]);

    $this->artisan('pwa:build')->assertSuccessful();

    Event::assertDispatched(ServiceWorkerBuilt::class, fn (ServiceWorkerBuilt $event): bool => strlen($event->version) === 12
        && str_ends_with($event->path, 'bootstrap/cache/pwa/sw.js')
        && $event->precache[0] === '/offline'
        && str_starts_with($event->precache[1], '/pwa/client.js?v='));
});

it('dispatches kill switch events', function (): void {
    Event::fake([KillSwitchActivated::class, KillSwitchDeactivated::class]);

    $this->artisan('pwa:disable', ['--force' => true])->assertSuccessful();
    Event::assertDispatched(KillSwitchActivated::class);

    $this->artisan('pwa:enable')->assertSuccessful();
    Event::assertDispatched(KillSwitchDeactivated::class);
});

it('lets ManifestResolving listeners change the manifest of the request only', function (): void {
    Event::listen(ManifestResolving::class, function (ManifestResolving $event): void {
        if ($event->request->query('dark') === '1') {
            $event->manifest->themeColor('#000000');
        }
    });

    $this->get('/manifest.webmanifest?dark=1')->assertJsonPath('theme_color', '#000000');
    $this->get('/manifest.webmanifest')->assertJsonPath('theme_color', '#0d6efd');
});

it('dispatches TelemetryReceived with normalised events', function (): void {
    Event::fake([TelemetryReceived::class]);

    $this->withHeader('User-Agent', 'Test/1.0')->postJson('/pwa/telemetry', [
        'source' => 'client',
        'events' => [['type' => 'error', 'message' => 'boom', 'display' => 'standalone']],
    ])->assertStatus(202)->assertJson(['logged' => 1]);

    Event::assertDispatched(TelemetryReceived::class, fn (TelemetryReceived $event): bool => $event->source === 'client'
        && $event->userAgent === 'Test/1.0'
        && $event->events === [['type' => 'error', 'message' => 'boom', 'stack' => null, 'version' => null, 'display' => 'standalone']]);
});

it('logs telemetry to the configured channel and can be turned off', function (): void {
    config(['logging.channels.pwa' => ['driver' => 'single', 'path' => storage_path('logs/pwa.log')], 'pwa.telemetry.channel' => 'pwa']);
    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === '[pwa] error: boom' && $context['source'] === 'sw');
    Log::shouldReceive('channel')->with('pwa')->once()->andReturn($channel);

    $event = new TelemetryReceived('sw', [['type' => 'error', 'message' => 'boom']], null);
    app(LogTelemetry::class)->handle($event);

    config(['pwa.telemetry.log' => false]);
    Log::shouldReceive('channel')->never();
    app(LogTelemetry::class)->handle($event);
});
