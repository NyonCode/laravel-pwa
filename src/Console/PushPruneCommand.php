<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use NyonCode\LaravelPwa\Push\WebPush;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'pwa:push:prune', description: 'Delete expired push subscriptions and those unused for pwa.push.prune_after_days days')]
class PushPruneCommand extends Command
{
    protected $signature = 'pwa:push:prune
        {--days= : Unused for this many days (overrides pwa.push.prune_after_days; 0 = expired only)}
        {--pretend : Count the subscriptions, delete nothing}';

    protected $description = 'Delete expired push subscriptions and those unused for pwa.push.prune_after_days days';

    public function handle(): int
    {
        $days = $this->option('days');

        if ($days !== null) {
            if (! ctype_digit($days)) {
                $this->components->error('--days must be a whole number of days.');

                return self::INVALID;
            }

            config(['pwa.push.prune_after_days' => (int) $days]);
        }

        $model = new (WebPush::subscriptionModel());

        if ($this->option('pretend')) {
            $this->components->info($model->prunable()->count().' push subscription(s) would be deleted.');

            return self::SUCCESS;
        }

        // MassPrunable: one DELETE per chunk, no model events (ModelsPruned is dispatched).
        $this->components->info($model->pruneAll().' push subscription(s) deleted.');

        return self::SUCCESS;
    }
}
