<?php

namespace NyonCode\LaravelPwa\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use NyonCode\LaravelPwa\PwaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Get the package service providers.
     *
     * @param  Application  $app
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PwaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://pwa.test');
        $app['config']->set('pwa.enabled', true);
        $app['config']->set('pwa.name', 'Testovací aplikace');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('pwa'));
        File::deleteDirectory(public_path('build'));
        File::deleteDirectory(base_path('bootstrap/cache/pwa'));

        parent::tearDown();
    }
}
