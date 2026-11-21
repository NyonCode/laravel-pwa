<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa;

use Closure;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Notifications\ChannelManager;
use NyonCode\LaravelPackageToolkit\Commands\InstallCommand;
use NyonCode\LaravelPackageToolkit\Packager;
use NyonCode\LaravelPackageToolkit\PackageServiceProvider;
use NyonCode\LaravelPwa\Console\BuildCommand;
use NyonCode\LaravelPwa\Console\ClearCommand;
use NyonCode\LaravelPwa\Console\DisableCommand;
use NyonCode\LaravelPwa\Console\DoctorCommand;
use NyonCode\LaravelPwa\Console\DownCommand;
use NyonCode\LaravelPwa\Console\EnableCommand;
use NyonCode\LaravelPwa\Console\IconsCommand;
use NyonCode\LaravelPwa\Console\PushVapidCommand;
use NyonCode\LaravelPwa\Contracts\PushTransport;
use NyonCode\LaravelPwa\Events\TelemetryReceived;
use NyonCode\LaravelPwa\Http\Middleware\CachePage;
use NyonCode\LaravelPwa\Http\Middleware\PwaWebMiddleware;
use NyonCode\LaravelPwa\Http\Middleware\RememberStandalone;
use NyonCode\LaravelPwa\Listeners\ForgetPushSubscriptions;
use NyonCode\LaravelPwa\Listeners\LogTelemetry;
use NyonCode\LaravelPwa\Listeners\MarkLogout;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;
use NyonCode\LaravelPwa\Push\WebPush;
use NyonCode\LaravelPwa\Push\WebPushChannel;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use NyonCode\LaravelPwa\Testing\PwaAssertions;

class PwaServiceProvider extends PackageServiceProvider
{
    public function configure(Packager $packager): void
    {
        $packager
            ->name('Laravel PWA')
            ->hasShortName('pwa')
            ->hasAbout()
            ->hasConfig()
            ->hasRoutes(['pwa.php'])
            ->hasViews()
            ->hasTranslations()
            // Push is opt-in: the migration is published (vendor:publish --tag="pwa::migrations"), never run by itself.
            ->hasMigrations()
            ->canLoadMigrations(false)
            ->hasMiddlewareAliases([
                'pwa.remember' => RememberStandalone::class,
                'pwa.cache' => CachePage::class,
            ])
            ->hasMiddlewareGroups([
                'web' => [PwaWebMiddleware::class],
            ])
            ->hasEvent(Logout::class, MarkLogout::class)
            ->hasEvent(Logout::class, ForgetPushSubscriptions::class)
            ->hasEvent(TelemetryReceived::class, LogTelemetry::class)
            ->hasCommands([
                BuildCommand::class,
                ClearCommand::class,
                DisableCommand::class,
                EnableCommand::class,
                DownCommand::class,
                DoctorCommand::class,
                IconsCommand::class,
                PushVapidCommand::class,
            ])
            ->hasOptimizeCommands(optimize: 'pwa:build', clear: 'pwa:clear', key: 'pwa')
            ->hasInstallCommand(fn (InstallCommand $command): InstallCommand => $command
                ->publishConfig()
                ->afterInstallation(function (InstallCommand $command): void {
                    $command->newLine();
                    $command->line('  Next steps:');
                    $command->line('  1. Add <x-pwa::head/> to the <head> of your layout (optionally also <x-pwa::nav/> and <x-pwa::install/>).');
                    $command->line('  2. Icons: php artisan pwa:icons resources/pwa/icon.png');
                    $command->line('  3. Add the pwa.remember middleware to your login route and pwa.cache to the pages that should work offline.');
                    $command->line('  4. Add `php artisan pwa:build` to your deploy script after `npm run build`.');
                    $command->line('  5. Check everything: php artisan pwa:doctor');
                }));
    }

    public function registeredPackage(): void
    {
        parent::registeredPackage();

        $this->app->singleton(Pwa::class);
        $this->app->singleton(ManifestBuilder::class);
        $this->app->singleton(ServiceWorkerBuilder::class, fn (): ServiceWorkerBuilder => new ServiceWorkerBuilder(
            runtimePath: __DIR__.'/../resources/sw/runtime.js',
            killSwitchPath: __DIR__.'/../resources/sw/kill-switch.js',
        ));
        $this->app->singleton(WebPush::class);
        $this->app->bind(PushTransport::class, static fn (): PushTransport => WebPush::defaultTransport());
    }

    public function bootedPackage(): void
    {
        parent::bootedPackage();

        // Display-mode hint from the client: unencrypted, nothing sensitive.
        EncryptCookies::except([Pwa::DISPLAY_COOKIE]);

        PwaAssertions::register();

        // via(): ['webpush'] as well as [WebPushChannel::class].
        $this->callAfterResolving(ChannelManager::class, static function (ChannelManager $channels): void {
            $channels->extend('webpush', fn (Application $app): WebPushChannel => $app->make(WebPushChannel::class));
        });
    }

    /**
     * @return array<string, string|Closure>
     */
    public function aboutData(): array
    {
        return [
            'Enabled' => fn (): string => app(Pwa::class)->enabled() ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>DISABLED</>',
            'Kill switch' => fn (): string => app(Pwa::class)->killed() ? '<fg=red;options=bold>ACTIVE</>' : 'off',
            'Service worker' => fn (): string => app(ServiceWorkerBuilder::class)->isCompiled() ? 'compiled' : '<fg=yellow>not built (pwa:build)</>',
            'Display' => fn (): string => config()->string('pwa.display.strategy', 'override'),
            'Web push' => fn (): string => app(WebPush::class)->enabled() ? '<fg=green;options=bold>ENABLED</>' : 'off',
        ];
    }
}
