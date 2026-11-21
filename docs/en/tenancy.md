---
title: Multi-tenant apps
description: One installable app per tenant on a shared domain (/t/acme/), with its own service worker scope, manifest identity and caches; recipes for stancl/tenancy and spatie/laravel-multitenancy.
---

# Multi-tenant apps

## Do you need this?

| Tenants live on | What to do |
| --- | --- |
| their own domain or subdomain (`acme.app.test`) | nothing: each origin has its own worker, caches and install. Use a [manifest resolver](manifest.md) for the name and colors. |
| paths of one domain (`app.test/t/acme/`) | configure a tenant resolver (this page) |

On a shared domain, the browser sees a single origin. Without a tenant resolver, every tenant is the
same installed app with one worker and one set of caches.

## Tenant resolver

```php
use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Contracts\TenantResolver;
use NyonCode\LaravelPwa\Tenancy\Tenant;

class PwaTenants implements TenantResolver
{
    // The tenant of a page request.
    public function current(Request $request): ?Tenant
    {
        $team = $request->route('team');

        return $team ? $this->toTenant($team) : null;
    }

    // The tenant with this key, for the manifest route (no route parameters, no session).
    public function find(string $key): ?Tenant
    {
        $team = Team::firstWhere('slug', $key);

        return $team ? $this->toTenant($team) : null;
    }

    private function toTenant(Team $team): Tenant
    {
        return new Tenant(
            key: $team->slug,
            scope: "/t/{$team->slug}/",
            startUrl: "/t/{$team->slug}/dashboard",
            name: $team->name,
        );
    }
}
```

```php
// config/pwa.php
'tenancy' => ['resolver' => App\Pwa\PwaTenants::class],

// or in AppServiceProvider::boot()
Pwa::resolveTenantsUsing(PwaTenants::class);
```

| `Tenant` argument | Rules |
| --- | --- |
| `key` | 1–64 letters, digits, `-`, `_`; public (it is in the manifest URL) |
| `scope` | path with a leading and trailing slash, e.g. `/t/acme/` |
| `startUrl` | inside the scope; defaults to the scope |
| `name`, `shortName` | optional manifest name; `shortName` defaults to `name` |

`find()` receives a key from the query string: return `null` for unknown keys and never expose a
tenant the key does not name.

## What changes on a tenant page

- `<x-pwa::head/>` registers the same `/sw.js` for the tenant scope, so each tenant has its own
  worker registration and update cycle.
- The manifest link gets `?tenant={key}`; the manifest then has the tenant's `id`, `scope`,
  `start_url` and `name`, so every tenant installs as a separate app. Your own resolvers run after
  this one and can change anything else (icons, colors).
- The worker names its caches after its scope: `pwa-@t-acme-pages-{user}`, `pwa-@t-acme-static-…`.
  The worker for `pwa.scope` keeps the plain names (`pwa-pages-{user}`), and no worker deletes
  another worker's caches.
- Pages outside every tenant keep the default scope and manifest.

What stays shared by the origin:

| Shared | Why it is fine |
| --- | --- |
| the offline queue (IndexedDB `pwa`) | queued requests are absolute URLs; any worker can send them |
| the kill switch | `pwa:disable` is an emergency stop for the whole origin |
| logout | `Clear-Site-Data: "cache"` clears the caches of every tenant |

## stancl/tenancy

With path identification (`InitializeTenancyByPath`, routes prefixed with `/{tenant}`):

```php
class PwaTenants implements TenantResolver
{
    public function current(Request $request): ?Tenant
    {
        return tenancy()->initialized ? $this->toTenant(tenant()) : null;
    }

    public function find(string $key): ?Tenant
    {
        $tenant = \App\Models\Tenant::find($key);

        return $tenant ? $this->toTenant($tenant) : null;
    }

    private function toTenant($tenant): Tenant
    {
        return new Tenant(key: $tenant->getTenantKey(), scope: "/{$tenant->getTenantKey()}/", name: $tenant->name);
    }
}
```

The manifest route runs outside the tenancy middleware, so `find()` must look the tenant up itself.

## spatie/laravel-multitenancy

```php
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

class PwaTenants implements TenantResolver
{
    public function current(Request $request): ?Tenant
    {
        $tenant = SpatieTenant::current();

        return $tenant ? $this->toTenant($tenant) : null;
    }

    public function find(string $key): ?Tenant
    {
        $tenant = SpatieTenant::firstWhere('slug', $key);

        return $tenant ? $this->toTenant($tenant) : null;
    }

    private function toTenant(SpatieTenant $tenant): Tenant
    {
        return new Tenant(key: $tenant->slug, scope: "/{$tenant->slug}/", name: $tenant->name);
    }
}
```

With spatie's default domain finder, tenants are on separate origins and you need no tenant resolver.

## Testing

```php
$this->get('/t/acme/dashboard')->assertSee('manifest.webmanifest?tenant=acme', false);

$this->get('/manifest.webmanifest?tenant=acme')
    ->assertJson(['id' => '/t/acme/', 'scope' => '/t/acme/'])
    ->assertValidManifest();
```

`pwa:doctor` reports whether the configured resolver can be built.
