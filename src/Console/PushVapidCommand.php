<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use NyonCode\LaravelPwa\Push\Vapid;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'pwa:push:vapid', description: 'Generate the VAPID key pair for web push and write it to .env')]
class PushVapidCommand extends Command
{
    protected $signature = 'pwa:push:vapid
        {--show : Print the keys instead of writing them}
        {--force : Replace existing keys (every current subscription stops working)}';

    protected $description = 'Generate the VAPID key pair for web push and write it to .env';

    public function handle(): int
    {
        $keys = Vapid::generate();

        $lines = [
            'PWA_VAPID_PUBLIC_KEY' => $keys['publicKey'],
            'PWA_VAPID_PRIVATE_KEY' => $keys['privateKey'],
        ];

        if ($this->option('show')) {
            foreach ($lines as $name => $value) {
                $this->line("{$name}={$value}");
            }

            return self::SUCCESS;
        }

        $path = $this->laravel->environmentFilePath();

        if (! File::exists($path)) {
            $this->components->error("No {$path}. Run with --show and store the keys in your secrets manager.");

            return self::FAILURE;
        }

        $env = File::get($path);

        if (preg_match('/^PWA_VAPID_PRIVATE_KEY=\S+/m', $env) === 1 && ! $this->option('force')) {
            $this->components->error('VAPID keys already exist. New keys invalidate every push subscription; use --force to replace them.');

            return self::FAILURE;
        }

        foreach ($lines as $name => $value) {
            $env = preg_match("/^{$name}=.*$/m", $env) === 1
                ? (string) preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $env)
                : rtrim($env, "\n")."\n{$name}={$value}\n";
        }

        File::put($path, $env);

        $this->components->info('VAPID keys written to .env.');
        $this->components->bulletList([
            'Set PWA_PUSH_ENABLED=true and PWA_VAPID_SUBJECT (mailto: or https:).',
            'Production: copy both keys to the server environment, never commit them.',
            'Run php artisan pwa:build: the worker carries the public key.',
        ]);

        if ($this->option('force')) {
            $this->components->warn('Existing subscriptions were made with the old key; browsers resubscribe on their next visit.');
        }

        return self::SUCCESS;
    }
}
