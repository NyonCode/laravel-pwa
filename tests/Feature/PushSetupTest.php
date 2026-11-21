<?php

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Minishlink\WebPush\WebPush as Minishlink;
use NyonCode\LaravelPwa\Facades\Pwa;
use NyonCode\LaravelPwa\Push\MinishlinkTransport;
use NyonCode\LaravelPwa\Push\PushSubscription;
use NyonCode\LaravelPwa\Push\Vapid;
use NyonCode\LaravelPwa\ServiceWorker\ServiceWorkerBuilder;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('registers nothing while push is off', function (): void {
    expect(Route::has('pwa.push.subscribe'))->toBeFalse()
        ->and(Pwa::clientConfig()['push'])->toBeNull()
        ->and(app(ServiceWorkerBuilder::class)->runtimeConfig()['push'])->toBeNull();

    $this->artisan('pwa:doctor')->doesntExpectOutputToContain('VAPID')->assertSuccessful();
});

it('publishes the migration with a timestamp', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'pwa::migrations'])->assertSuccessful();

    $published = File::glob(database_path('migrations/*_create_pwa_push_subscriptions_table.php'));
    expect($published)->toHaveCount(1);

    File::delete($published);
});

describe('pwa:push:vapid', function (): void {
    beforeEach(function (): void {
        $this->env = $this->app->environmentFilePath();
        $this->backup = File::exists($this->env) ? File::get($this->env) : null;
        File::put($this->env, "APP_NAME=Test\nPWA_VAPID_PUBLIC_KEY=\n");
    });

    afterEach(function (): void {
        $this->backup === null ? File::delete($this->env) : File::put($this->env, $this->backup);
    });

    it('writes a new key pair to .env', function (): void {
        $this->artisan('pwa:push:vapid')->expectsOutputToContain('VAPID keys written to .env.')->assertSuccessful();

        preg_match('/^PWA_VAPID_PUBLIC_KEY=(\S+)$/m', File::get($this->env), $public);
        preg_match('/^PWA_VAPID_PRIVATE_KEY=(\S+)$/m', File::get($this->env), $private);

        expect(Vapid::problem($public[1] ?? null, $private[1] ?? null, 'mailto:ops@pwa.test'))->toBeNull()
            ->and(substr_count(File::get($this->env), 'PWA_VAPID_PUBLIC_KEY='))->toBe(1)
            ->and(File::get($this->env))->toStartWith("APP_NAME=Test\n");
    });

    it('keeps existing keys unless forced', function (): void {
        $this->artisan('pwa:push:vapid')->assertSuccessful();
        $before = File::get($this->env);

        $this->artisan('pwa:push:vapid')->expectsOutputToContain('VAPID keys already exist')->assertFailed();
        expect(File::get($this->env))->toBe($before);

        $this->artisan('pwa:push:vapid', ['--force' => true])->expectsOutputToContain('browsers resubscribe')->assertSuccessful();
        expect(File::get($this->env))->not->toBe($before);
    });

    it('prints the keys with --show', function (): void {
        $this->artisan('pwa:push:vapid', ['--show' => true])->expectsOutputToContain('PWA_VAPID_PRIVATE_KEY=')->assertSuccessful();

        expect(File::get($this->env))->toBe("APP_NAME=Test\nPWA_VAPID_PUBLIC_KEY=\n");
    });
});

describe('minishlink transport', function (): void {
    beforeEach(function (): void {
        if (! (new ReflectionParameter([Minishlink::class, '__construct'], 2))->getType() instanceof ReflectionNamedType
            || (new ReflectionParameter([Minishlink::class, '__construct'], 2))->getType()->getName() !== ClientInterface::class) {
            $this->markTestSkipped('minishlink/web-push 10 has no PSR-18 client argument');
        }
    });

    /**
     * @param  list<int>  $statuses
     * @return array{0: MinishlinkTransport, 1: ArrayObject<int, RequestInterface>}
     */
    function transportAnswering(array $statuses): array
    {
        $requests = new ArrayObject;
        $client = new class($statuses, $requests) implements ClientInterface
        {
            /** @param  list<int>  $statuses */
            public function __construct(private array $statuses, private readonly ArrayObject $requests) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests->append($request);

                return new Response(array_shift($this->statuses) ?? 201);
            }
        };
        $keys = Vapid::generate();

        return [new MinishlinkTransport(['subject' => 'mailto:ops@pwa.test', ...$keys], $client), $requests];
    }

    function browserKeys(): PushSubscription
    {
        $subscription = new PushSubscription;
        $subscription->forceFill([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/real',
            'public_key' => Vapid::generate()['publicKey'],
            'auth_token' => Vapid::encode(random_bytes(16)),
            'content_encoding' => 'aes128gcm',
        ]);

        return $subscription;
    }

    it('encrypts the payload and signs the request with VAPID', function (): void {
        [$transport, $requests] = transportAnswering([201]);

        $reports = $transport->send([['subscription' => browserKeys(), 'payload' => '{"title":"plaintext-marker-5f2c9a7e41d84b06"}', 'options' => ['TTL' => 60, 'urgency' => 'high', 'topic' => 'news']]]);

        $request = $requests[0];
        expect($reports[0]->success)->toBeTrue()
            ->and((string) $request->getUri())->toBe('https://fcm.googleapis.com/fcm/send/real')
            ->and($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
            ->and($request->getHeaderLine('TTL'))->toBe('60')
            ->and($request->getHeaderLine('Urgency'))->toBe('high')
            ->and($request->getHeaderLine('Topic'))->toBe('news')
            ->and($request->getHeaderLine('Authorization'))->toStartWith('vapid t=')
            ->and((string) $request->getBody())->not->toContain('plaintext-marker-5f2c9a7e41d84b06');
    });

    it('reports expired subscriptions', function (): void {
        [$transport] = transportAnswering([410]);

        $report = $transport->send([['subscription' => browserKeys(), 'payload' => '{}', 'options' => []]])[0];

        expect($report->success)->toBeFalse()->and($report->expired())->toBeTrue()->and($report->status)->toBe(410);
    });

    it('sends nothing for an empty batch', function (): void {
        expect((new MinishlinkTransport(['subject' => 'mailto:a@b.c', ...Vapid::generate()]))->send([]))->toBe([]);
    });
});

it('refuses to build the default transport without keys', function (): void {
    config(['pwa.push.vapid.public_key' => null]);

    NyonCode\LaravelPwa\Push\WebPush::defaultTransport();
})->throws(RuntimeException::class, 'Web push: VAPID keys are missing');
