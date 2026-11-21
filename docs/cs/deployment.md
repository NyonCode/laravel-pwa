---
title: Nasazení
description: Deploy skript, více serverů, Octane, CDN, nginx a Docker – co musí platit v produkci.
---

# Nasazení

## Deploy skript

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize          # zahrnuje pwa:build
php artisan pwa:doctor --ci   # zastaví deploy při chybě
php artisan migrate --force
```

Pořadí je důležité: `pwa:build` čte `public/build/manifest.json`, takže až po `npm run build`.

S údržbou:

```bash
php artisan pwa:down --message="Nasazujeme novou verzi" --retry=60
# … deploy …
php artisan up
```

Zero-downtime nástroje (Envoyer, Deployer, Forge): `pwa:build` v kroku po buildu assetů, uvnitř
nového release adresáře. `bootstrap/cache/pwa/` je součástí release, ne sdíleného úložiště.

## Více serverů

| Co | Požadavek |
| --- | --- |
| `bootstrap/cache/pwa/sw.js` | sestavit na každém serveru ze stejného buildu (nebo jednou a distribuovat s release) |
| nouzové vypnutí | `PWA_KILL_SWITCH_STORE=redis` (sdílený store) |
| session | sdílená (`database`, `redis`) |

Protože verze SW je hash obsahu, stejný build = stejný soubor na všech serverech a prohlížeč
nepřepíná tam a zpět.

## Octane

Balíček nedrží stav mezi požadavky: `/sw.js` čte hotový soubor, manifest se sestaví z configu.
Fluent volání `Pwa::manifest()` patří do `boot()` a platí pro všechny požadavky. Pod Octane
toolkit sám obsluhuje životní cyklus providerů.

## CDN a reverse proxy

| Cesta | Cache |
| --- | --- |
| `/sw.js` | neměla by se cachovat (`no-cache` z aplikace respektovat) |
| `/manifest.webmanifest` | 1 h; u `adaptive` jen pokud CDN respektuje `Vary` |
| `/pwa/client.js?v=…` | dlouhodobě (`immutable`) |
| `/pwa/session`, `/pwa/ping`, `/pwa/telemetry` | nikdy |
| `/build/*` | dlouhodobě (hash v názvu) |

Cloudflare: pro `/sw.js` a `/pwa/*` pravidlo „Bypass cache“.

## nginx

Standardní Laravel konfigurace stačí (`try_files $uri $uri/ /index.php?$query_string;`), protože
`/sw.js` a manifest jsou routy. Nesmí ale existovat `public/sw.js` ani
`public/manifest.webmanifest` – měly by přednost.

## Docker a read-only souborový systém

`pwa:build` zapisuje do `bootstrap/cache/pwa/`. V image ho spusťte při buildu (po `npm run build`),
nebo ho spusťte při startu kontejneru. Bez buildu se SW sestavuje za běhu (funguje i na
read-only disku, jen pomaleji).

`pwa:icons` patří do vývoje (výstup commitněte do `public/pwa/icons/`), ne do deploye.

## HTTPS

Service worker vyžaduje HTTPS (kromě `localhost`). Za proxy nastavte trusted proxies, aby
`APP_URL` i generované URL byly `https`.
