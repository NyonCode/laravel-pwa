---
title: Více tenantů
description: Samostatně instalovatelná aplikace pro každého tenanta na sdílené doméně (/t/acme/) s vlastním scope service workeru, identitou v manifestu a cache; recepty pro stancl/tenancy a spatie/laravel-multitenancy.
---

# Více tenantů

## Potřebujete to?

| Tenanti jsou na | Co udělat |
| --- | --- |
| vlastní doméně nebo subdoméně (`acme.app.test`) | nic: každý origin má vlastní worker, cache i instalaci. Název a barvy řeší [resolver manifestu](manifest.md). |
| cestách jedné domény (`app.test/t/acme/`) | nastavit resolver tenantů (tato stránka) |

Na sdílené doméně prohlížeč vidí jediný origin. Bez resolveru tenantů jsou všichni tenanti jedna
nainstalovaná aplikace s jedním workerem a jednou sadou cache.

## Resolver tenantů

```php
use Illuminate\Http\Request;
use NyonCode\LaravelPwa\Contracts\TenantResolver;
use NyonCode\LaravelPwa\Tenancy\Tenant;

class PwaTenants implements TenantResolver
{
    // Tenant stránky, kterou uživatel otevřel.
    public function current(Request $request): ?Tenant
    {
        $team = $request->route('team');

        return $team ? $this->toTenant($team) : null;
    }

    // Tenant s tímto klíčem, pro routu manifestu (bez parametrů routy a bez session).
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

// nebo v AppServiceProvider::boot()
Pwa::resolveTenantsUsing(PwaTenants::class);
```

| Argument `Tenant` | Pravidla |
| --- | --- |
| `key` | 1–64 písmen, číslic, `-`, `_`; veřejný (je v URL manifestu) |
| `scope` | cesta s lomítkem na začátku i na konci, např. `/t/acme/` |
| `startUrl` | uvnitř scope; výchozí je scope |
| `name`, `shortName` | volitelný název v manifestu; `shortName` je výchozí `name` |

`find()` dostává klíč z query stringu: pro neznámý klíč vraťte `null` a nikdy nevracejte tenanta,
kterého klíč nejmenuje.

## Co se na stránce tenanta změní

- `<x-pwa::head/>` zaregistruje stejný `/sw.js` pro scope tenanta, takže každý tenant má vlastní
  registraci workeru i cyklus aktualizací.
- Odkaz na manifest dostane `?tenant={key}`; manifest pak má `id`, `scope`, `start_url` a `name`
  tenanta, takže se každý tenant instaluje jako samostatná aplikace. Vaše resolvery běží až po tomto
  a mohou změnit cokoli dalšího (ikony, barvy).
- Worker pojmenuje cache podle svého scope: `pwa-@t-acme-pages-{user}`, `pwa-@t-acme-static-…`.
  Worker pro `pwa.scope` si nechá prosté názvy (`pwa-pages-{user}`) a žádný worker nemaže cache
  jiného workeru.
- Stránky mimo všechny tenanty mají výchozí scope a manifest.

Co zůstává společné pro celý origin:

| Společné | Proč to nevadí |
| --- | --- |
| fronta offline akcí (IndexedDB `pwa`) | požadavky ve frontě mají absolutní URL; odeslat je může kterýkoli worker |
| nouzové vypnutí | `pwa:disable` je nouzová brzda pro celý origin |
| odhlášení | `Clear-Site-Data: "cache"` smaže cache všech tenantů |

## stancl/tenancy

S identifikací podle cesty (`InitializeTenancyByPath`, routy s prefixem `/{tenant}`):

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

Routa manifestu běží mimo tenancy middleware, takže `find()` musí tenanta dohledat sám.

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

S výchozím vyhledáváním podle domény jsou tenanti na samostatných originech a resolver tenantů
nepotřebujete.

## Testování

```php
$this->get('/t/acme/dashboard')->assertSee('manifest.webmanifest?tenant=acme', false);

$this->get('/manifest.webmanifest?tenant=acme')
    ->assertJson(['id' => '/t/acme/', 'scope' => '/t/acme/'])
    ->assertValidManifest();
```

`pwa:doctor` ohlásí, jestli jde nastavený resolver sestavit.
