<?php

declare(strict_types=1);

namespace NyonCode\LaravelPwa\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use NyonCode\LaravelPwa\Http\Middleware\CachePage;
use NyonCode\LaravelPwa\Manifest\ManifestValidator;
use NyonCode\LaravelPwa\Pwa;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * TestResponse macros, registered by the service provider when illuminate/testing is available:
 *
 *     $this->get('/dashboard')->assertPwaCacheable();
 *     $this->get('/billing')->assertNotPwaCacheable();
 *     $this->post('/logout')->assertPwaLogout();
 *     $this->actingAs($user)->get('/')->assertPwaUser($user);
 *     $this->get('/manifest.webmanifest')->assertValidManifest();
 */
final class PwaAssertions
{
    public static function register(): void
    {
        if (! class_exists(TestResponse::class)) {
            return;
        }

        TestResponse::macro('assertPwaCacheable', function (): TestResponse {
            /** @var TestResponse<Response> $this */
            Assert::assertSame('allow', $this->headers->get(CachePage::HEADER), 'The page is not allowed offline: add the pwa.cache middleware.');
            Assert::assertStringNotContainsString('no-store', (string) $this->headers->get('Cache-Control'), 'The page is marked Cache-Control: no-store, the service worker will not store it.');

            return $this;
        });

        TestResponse::macro('assertNotPwaCacheable', function (): TestResponse {
            /** @var TestResponse<Response> $this */
            Assert::assertNotSame('allow', $this->headers->get(CachePage::HEADER), 'The page is allowed offline (X-PWA-Cache: allow).');

            return $this;
        });

        TestResponse::macro('assertPwaLogout', function (): TestResponse {
            /** @var TestResponse<Response> $this */
            Assert::assertSame('1', $this->headers->get('X-PWA-Logout'), 'No X-PWA-Logout header: the worker will not purge the user data.');
            Assert::assertStringNotContainsString('storage', (string) $this->headers->get('Clear-Site-Data'), 'Clear-Site-Data must not contain "storage": it unregisters the worker.');

            return $this;
        });

        TestResponse::macro('assertPwaUser', function (Authenticatable|string|null $user = null): TestResponse {
            /** @var TestResponse<Response> $this */
            $expected = match (true) {
                $user === null => 'guest',
                is_string($user) => $user,
                default => app(Pwa::class)->userHash(tap(Request::create('/'), static fn (Request $r) => $r->setUserResolver(static fn (): Authenticatable => $user))),
            };

            Assert::assertSame($expected, $this->headers->get('X-PWA-User'), 'Unexpected X-PWA-User identity for the service worker.');

            return $this;
        });

        TestResponse::macro('assertValidManifest', function (bool $strict = false): TestResponse {
            /** @var TestResponse<Response> $this */
            $manifest = json_decode((string) $this->getContent(), true);
            Assert::assertIsArray($manifest, 'The response is not a JSON manifest.');

            $result = app(ManifestValidator::class)->validate($manifest);
            $problems = $strict ? [...$result['errors'], ...$result['warnings']] : $result['errors'];

            Assert::assertSame([], $problems, "The manifest is not installable:\n- ".implode("\n- ", $problems));

            return $this;
        });
    }
}
