<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use NyonCode\LaravelPwa\Contracts\ManifestParameters;
use NyonCode\LaravelPwa\Contracts\ManifestResolver;
use NyonCode\LaravelPwa\Facades\Pwa;
use NyonCode\LaravelPwa\Manifest\ManifestBuilder;

afterEach(fn () => Pwa::manifest()->flush());

/**
 * A tenant resolver as an application would write it: the tenant comes from the page
 * (ManifestParameters) and is validated on the manifest route (ManifestResolver).
 */
final class TenantManifestResolver implements ManifestParameters, ManifestResolver
{
    public const TENANTS = ['acme' => ['Acme Orders', '#ff0000'], 'globex' => ['Globex', '#00ff00']];

    public function parameters(Request $page): array
    {
        $tenant = $page->attributes->get('tenant');

        return is_string($tenant) ? ['tenant' => $tenant] : [];
    }

    public function resolve(Request $request, ManifestBuilder $manifest): void
    {
        $tenant = self::TENANTS[$request->query('tenant')] ?? null;

        if ($tenant !== null) {
            $manifest->name($tenant[0])->shortName($tenant[0])->themeColor($tenant[1])
                ->id('/?tenant='.$request->query('tenant'))->startUrl('/?tenant='.$request->query('tenant'))
                ->private();
        }
    }
}

function renderHead(?Request $page = null): string
{
    if ($page instanceof Request) {
        app()->instance('request', $page);
    }

    return Blade::render('<x-pwa::head/>');
}

describe('resolvers', function (): void {
    it('applies a closure resolver to the manifest route', function (): void {
        Pwa::manifest()->resolveUsing(fn (Request $request, ManifestBuilder $manifest): ManifestBuilder => $manifest
            ->name('Resolved')->themeColor('#123456'));

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'Resolved')
            ->assertJsonPath('theme_color', '#123456');
    });

    it('resolves classes from pwa.manifest.resolvers through the container', function (): void {
        config(['pwa.manifest.resolvers' => [TenantManifestResolver::class]]);

        $this->get('/manifest.webmanifest?tenant=acme')
            ->assertJsonPath('name', 'Acme Orders')
            ->assertJsonPath('id', '/?tenant=acme')
            ->assertHeader('Cache-Control', 'max-age=3600, private');

        $this->get('/manifest.webmanifest?tenant=unknown')
            ->assertJsonPath('name', 'Testovací aplikace')
            ->assertHeader('Cache-Control', 'max-age=3600, public');
    });

    it('does not leak resolver changes into the next request (Octane)', function (): void {
        Pwa::manifest()->resolveUsing(TenantManifestResolver::class);

        $this->get('/manifest.webmanifest?tenant=globex')->assertJsonPath('name', 'Globex');
        $this->get('/manifest.webmanifest')->assertJsonPath('name', 'Testovací aplikace');

        expect(Pwa::manifest()->toArray()['name'])->toBe('Testovací aplikace');
    });

    it('keeps boot-time fluent settings and applies resolvers on top', function (): void {
        Pwa::manifest()->shortcut('Orders', '/orders')->resolveUsing(fn ($request, $manifest) => $manifest->name('Later'));

        $manifest = $this->get('/manifest.webmanifest')->json();

        expect($manifest['name'])->toBe('Later')
            ->and($manifest['shortcuts'][0]['url'])->toBe('/orders');
    });

    it('sends Vary for headers a resolver depends on', function (): void {
        Pwa::manifest()->resolveUsing(fn (Request $request, ManifestBuilder $manifest): ManifestBuilder => $manifest->vary('Host'));

        $this->get('/manifest.webmanifest')->assertHeader('Vary', 'Host');
    });

    it('rejects a resolver that is neither a ManifestResolver nor a closure', function (): void {
        Pwa::manifest()->resolveUsing(stdClass::class);

        Pwa::manifest()->forRequest(request());
    })->throws(InvalidArgumentException::class, 'must implement');

    it('reports a failing resolver in pwa:doctor', function (): void {
        Pwa::manifest()->resolveUsing(fn (): never => throw new RuntimeException('tenant table missing'));

        $this->artisan('pwa:doctor')->expectsOutputToContain('tenant table missing')->assertSuccessful();
        $this->artisan('pwa:doctor', ['--ci' => true])->assertFailed();
    });
});

describe('manifest link parameters', function (): void {
    it('appends parameters of class resolvers to the manifest link', function (): void {
        config(['pwa.manifest.resolvers' => [TenantManifestResolver::class]]);
        $page = Request::create('/dashboard');
        $page->attributes->set('tenant', 'acme');

        $html = renderHead($page);

        expect($html)->toContain('<link rel="manifest" href="/manifest.webmanifest?tenant=acme">')
            ->and($html)->toContain('content="#ff0000" media="(prefers-color-scheme: light)"')
            ->and($html)->toContain('<meta name="apple-mobile-web-app-title" content="Acme Orders">');
    });

    it('appends parameters of closure resolvers', function (): void {
        Pwa::manifest()->resolveUsing(
            fn (Request $request, ManifestBuilder $manifest): ManifestBuilder => $manifest->name('Team '.$request->query('team')),
            fn (Request $page): array => ['team' => 'blue'],
        );

        expect(renderHead(Request::create('/')))->toContain('href="/manifest.webmanifest?team=blue"')
            ->and($this->get('/manifest.webmanifest?team=blue')->json('name'))->toBe('Team blue');
    });

    it('keeps the plain link without parameters', function (): void {
        expect(renderHead(Request::create('/')))->toContain('<link rel="manifest" href="/manifest.webmanifest">');
    });

    it('rejects parameters that are not strings', function (): void {
        Pwa::manifest()->resolveUsing(fn (): null => null, fn (): array => ['team' => ['blue']]);

        Pwa::manifest()->parameters(Request::create('/'));
    })->throws(InvalidArgumentException::class, 'array of strings');
});

describe('locale', function (): void {
    beforeEach(function (): void {
        config(['app.locale' => 'en', 'pwa.locales' => ['en', 'cs', 'pt_BR']]);
        app('translator')->addLines(['manifest.name' => 'Objednávky'], 'cs', 'pwa');
        app('translator')->addLines(['manifest.name' => 'Pedidos'], 'pt_BR', 'pwa');
    });

    it('links the manifest in the page locale', function (): void {
        app()->setLocale('cs');

        expect(renderHead(Request::create('/')))->toContain('href="/manifest.webmanifest?lang=cs"');
    });

    it('links the default locale explicitly and adds nothing for an unlisted one', function (): void {
        app()->setLocale('en');
        expect(renderHead(Request::create('/')))->toContain('href="/manifest.webmanifest?lang=en"');

        app()->setLocale('pt_BR');
        expect(renderHead(Request::create('/')))->toContain('href="/manifest.webmanifest?lang=pt-BR"');

        app()->setLocale('de');
        expect(renderHead(Request::create('/')))->toContain('href="/manifest.webmanifest"');
    });

    it('builds the manifest in the requested locale', function (): void {
        $this->get('/manifest.webmanifest?lang=cs')
            ->assertJsonPath('name', 'Objednávky')
            ->assertJsonPath('lang', 'cs')
            ->assertHeader('Content-Language', 'cs');

        $this->get('/manifest.webmanifest?lang=pt-BR')
            ->assertJsonPath('name', 'Pedidos')
            ->assertJsonPath('lang', 'pt-BR');
    });

    it('ignores locales that are not configured', function (): void {
        $this->get('/manifest.webmanifest?lang=xx')
            ->assertJsonPath('lang', 'en')
            ->assertJsonPath('name', 'Testovací aplikace');
    });

    it('gives each locale its own ETag', function (): void {
        $cs = $this->get('/manifest.webmanifest?lang=cs')->headers->get('ETag');
        $en = $this->get('/manifest.webmanifest')->headers->get('ETag');

        expect($cs)->not->toBe($en);
    });

    it('does not change the application locale', function (): void {
        $this->get('/manifest.webmanifest?lang=cs');

        expect(app()->getLocale())->toBe('en')
            ->and(config('app.locale'))->toBe('en');
    });
});

describe('typed members', function (): void {
    it('sets identity, colours and URLs', function (): void {
        Pwa::manifest()->name('A')->shortName('B')->description('C')->id('/app')->startUrl('/start')
            ->scope('/app/')->themeColor('#111111')->backgroundColor('#222222');

        expect($this->get('/manifest.webmanifest')->json())->toMatchArray([
            'name' => 'A', 'short_name' => 'B', 'description' => 'C', 'id' => '/app',
            'start_url' => '/start', 'scope' => '/app/', 'theme_color' => '#111111', 'background_color' => '#222222',
        ]);
    });

    it('replaces generated icons once custom icons are added', function (): void {
        File::ensureDirectoryExists(public_path('pwa/icons'));
        File::put(public_path('pwa/icons/icon-192.png'), 'x');

        Pwa::manifest()->icon('/brand/acme.svg', 'any')->icon('/brand/acme-512.png', '512x512', purpose: 'maskable')->icon('/brand/x.bin', '48x48');

        expect($this->get('/manifest.webmanifest')->json('icons'))->toBe([
            ['src' => '/brand/acme.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ['src' => '/brand/acme-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => '/brand/x.bin', 'sizes' => '48x48', 'purpose' => 'any'],
        ]);
    });
});
