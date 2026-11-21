<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa;

use Illuminate\Auth\Events\Logout;
use Illuminate\Cookie\Middleware\EncryptCookies;
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
use NyonCode\LaravelPwa\Http\Middleware\CachePage;
use NyonCode\LaravelPwa\Http\Middleware\PwaWebMiddleware;
use NyonCode\LaravelPwa\Http\Middleware\RememberStandalone;
use NyonCode\LaravelPwa\Listeners\MarkLogout;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;

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
            ->hasMiddlewareAliases([
                'pwa.remember' => RememberStandalone::class,
                'pwa.cache' => CachePage::class,
            ])
            ->hasMiddlewareGroups([
                'web' => [PwaWebMiddleware::class],
            ])
            ->hasEvent(Logout::class, MarkLogout::class)
            ->hasCommands([
                BuildCommand::class,
                ClearCommand::class,
                DisableCommand::class,
                EnableCommand::class,
                DownCommand::class,
                DoctorCommand::class,
                IconsCommand::class,
            ])
            ->hasOptimizeCommands(optimize: 'pwa:build', clear: 'pwa:clear', key: 'pwa')
            ->hasInstallCommand(fn (InstallCommand $command): InstallCommand => $command
                ->publishConfig()
                ->afterInstallation(function (InstallCommand $command): void {
                    $command->newLine();
                    $command->line('  Další kroky:');
                    $command->line('  1. Do <head> layoutu vložte <x-pwa::head/> (a případně <x-pwa::nav/>, <x-pwa::install/>).');
                    $command->line('  2. Ikony: php artisan pwa:icons resources/pwa/icon.png');
                    $command->line('  3. Na login routu přidejte middleware pwa.remember, na offline stránky pwa.cache.');
                    $command->line('  4. Do deploy skriptu za `npm run build` přidejte `php artisan pwa:build`.');
                    $command->line('  5. Zkontrolujte vše: php artisan pwa:doctor');
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
    }

    public function bootedPackage(): void
    {
        parent::bootedPackage();

        // Nápověda o režimu zobrazení z klienta: nešifrovaná, nic citlivého.
        EncryptCookies::except([Pwa::DISPLAY_COOKIE]);
    }

    /**
     * @return array<string, string|\Closure>
     */
    public function aboutData(): array
    {
        return [
            'Enabled' => fn (): string => app(Pwa::class)->enabled() ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>DISABLED</>',
            'Kill switch' => fn (): string => app(Pwa::class)->killed() ? '<fg=red;options=bold>ACTIVE</>' : 'off',
            'Service worker' => fn (): string => app(ServiceWorkerBuilder::class)->isCompiled() ? 'compiled' : '<fg=yellow>not built (pwa:build)</>',
            'Display' => fn (): string => (string) config('pwa.display.strategy', 'override'),
        ];
    }
}
