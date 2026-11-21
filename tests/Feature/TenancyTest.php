<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use NyonCode\LaravelPwa\Contracts\TenantResolver;
use NyonCode\LaravelPwa\Pwa;
use NyonCode\LaravelPwa\Tenancy\Tenant;

/**
 * Tenants under /t/{key}/, as an application would resolve them from its own model.
 */
class PathTenants implements TenantResolver
{
    public const TENANTS = ['acme' => 'Acme Orders', 'globex' => null];

    public function current(Request $request): ?Tenant
    {
        return preg_match('#^t/([a-z]+)(/|$)#', $request->path(), $m) === 1 ? $this->find($m[1]) : null;
    }

    public function find(string $key): ?Tenant
    {
        return array_key_exists($key, self::TENANTS)
            ? new Tenant($key, "/t/{$key}/", "/t/{$key}/?source=pwa", self::TENANTS[$key])
            : null;
    }
}

beforeEach(function (): void {
    config(['pwa.tenancy.resolver' => PathTenants::class]);

    Route::middleware('web')->group(function (): void {
        Route::get('/t/{tenant}/dashboard', fn (): string => Blade::render('<x-pwa::head/>'));
        Route::get('/landing', fn (): string => Blade::render('<x-pwa::head/>'));
    });
});

function pwaConfig(string $html): array
{
    preg_match('#<script type="application/json" id="pwa-config">(.*?)</script>#s', $html, $m);

    return json_decode(html_entity_decode($m[1]), true, flags: JSON_THROW_ON_ERROR);
}

it('validates tenant keys, scopes and start URLs', function (string $key, string $scope, ?string $start): void {
    new Tenant($key, $scope, $start);
})->throws(InvalidArgumentException::class)->with([
    'key with a slash' => ['a/b', '/t/a/', null],
    'empty key' => ['', '/t/a/', null],
    'scope without trailing slash' => ['a', '/t/a', null],
    'relative scope' => ['a', 't/a/', null],
    'scope with ..' => ['a', '/t/../', null],
    'start URL outside the scope' => ['a', '/t/a/', '/other'],
]);

it('defaults the start URL to the scope', function (): void {
    expect((new Tenant('acme', '/t/acme/'))->startUrl)->toBe('/t/acme/');
});

it('registers the worker for the tenant scope and links the tenant manifest', function (): void {
    $html = $this->get('/t/acme/dashboard')->assertOk()->getContent();

    expect(pwaConfig($html))->toMatchArray(['scope' => '/t/acme/', 'startUrl' => '/t/acme/?source=pwa', 'tenant' => 'acme'])
        ->and($html)->toContain('<link rel="manifest" href="/manifest.webmanifest?tenant=acme">')
        ->and($html)->toContain('content="Acme Orders"');
});

it('keeps the default scope outside any tenant', function (): void {
    $html = $this->get('/landing')->getContent();

    expect(pwaConfig($html))->toMatchArray(['scope' => '/', 'tenant' => null])
        ->and($html)->toContain('<link rel="manifest" href="/manifest.webmanifest">');
});

it('gives every tenant its own manifest identity', function (): void {
    $this->get('/manifest.webmanifest?tenant=acme')
        ->assertOk()
        ->assertJson(['id' => '/t/acme/', 'scope' => '/t/acme/', 'start_url' => '/t/acme/?source=pwa', 'name' => 'Acme Orders', 'short_name' => 'Acme Orders']);

    $globex = $this->get('/manifest.webmanifest?tenant=globex')->assertOk()->json();
    expect([$globex['id'], $globex['scope'], $globex['name']])->toBe(['/t/globex/', '/t/globex/', config('pwa.name')]);
});

it('ignores unknown tenant keys on the manifest route', function (): void {
    $this->get('/manifest.webmanifest?tenant=nope')->assertOk()->assertJson(['id' => '/', 'scope' => '/']);
    $this->get('/manifest.webmanifest?tenant[]=acme')->assertOk()->assertJson(['scope' => '/']);
});

it('never takes the tenant from the query string of a page', function (): void {
    $html = $this->get('/landing?tenant=acme')->getContent();

    expect(pwaConfig($html)['tenant'])->toBeNull();
});

it('resolves the tenant once per request', function (): void {
    $calls = 0;
    app(Pwa::class)->resolveTenantsUsing(new class($calls) implements TenantResolver
    {
        public function __construct(private int &$calls) {}

        public function current(Request $request): Tenant
        {
            $this->calls++;

            return new Tenant('acme', '/t/acme/');
        }

        public function find(string $key): ?Tenant
        {
            return null;
        }
    });

    $request = Request::create('/t/acme/x');
    app(Pwa::class)->tenant($request);
    app(Pwa::class)->tenant($request);

    expect($calls)->toBe(1);
});

it('stays off without a configured resolver', function (): void {
    config(['pwa.tenancy.resolver' => null]);

    expect(app(Pwa::class)->tenancy())->toBeNull()
        ->and(app(Pwa::class)->tenant(Request::create('/t/acme/')))->toBeNull();
});

it('rejects a resolver that does not implement the contract', function (): void {
    config(['pwa.tenancy.resolver' => stdClass::class]);

    expect(fn () => app(Pwa::class)->tenancy())->toThrow(InvalidArgumentException::class, TenantResolver::class);

    $this->artisan('pwa:doctor')->expectsOutputToContain('Tenant resolver: The tenant resolver must implement')->assertSuccessful();
});

it('reports a working tenant resolver in pwa:doctor', function (): void {
    $this->artisan('pwa:doctor')->expectsOutputToContain('Tenant resolver PathTenants')->assertSuccessful();
});
