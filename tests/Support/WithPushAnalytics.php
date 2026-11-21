<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Tests\Support;

/**
 * WithPush plus pwa.push.analytics, set before the routes load.
 */
trait WithPushAnalytics
{
    use WithPush {
        enablePush as enablePushModule;
    }

    protected function enablePush(mixed $app): void
    {
        $this->enablePushModule($app);
        $app['config']->set('pwa.push.analytics', true);
    }
}
