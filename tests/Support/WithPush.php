<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Tests\Support;

use NyonCode\LaravelPwa\Contracts\PushTransport;
use NyonCode\LaravelPwa\Push\Vapid;
use NyonCode\LaravelPwa\Tests\Fixtures\FakeTransport;

/**
 * Turns the push module on before the routes load, migrates the users and subscriptions
 * tables and swaps the transport for FakeTransport.
 */
trait WithPush
{
    /** @var array{publicKey: string, privateKey: string}|null */
    protected static ?array $vapid = null;

    protected function enablePush(mixed $app): void
    {
        self::$vapid ??= Vapid::generate();

        $app['config']->set('pwa.push.enabled', true);
        $app['config']->set('pwa.push.vapid', [
            'subject' => 'mailto:ops@pwa.test',
            'public_key' => self::$vapid['publicKey'],
            'private_key' => self::$vapid['privateKey'],
        ]);
    }

    protected function setUpWithPush(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        $this->app->instance(PushTransport::class, new FakeTransport);
    }

    protected function transport(): FakeTransport
    {
        $transport = $this->app->make(PushTransport::class);
        assert($transport instanceof FakeTransport);

        return $transport;
    }
}
