---
title: Verzování
description: "Sémantické verzování od 1.0: co patří do veřejného API (PHP, config, Blade, JavaScript, worker), co se smí změnit v minor verzi a jaká je politika podpory."
---

# Verzování

Od verze 1.0 se balíček řídí [sémantickým verzováním](https://semver.org): minor verze (1.x) přidává
funkce a nikdy nerozbije veřejné API, patch verze (1.x.y) jen opravuje chyby a cokoli, co API rozbíjí,
počká na 2.0 s návodem v `UPGRADE.md`.

## Veřejné API

| Oblast | Veřejné |
| --- | --- |
| PHP | fasády `Pwa` a `WebPush`, `Pwa`, `ManifestBuilder`, `ServiceWorkerBuilder`, `CacheRule`, `CacheStrategy`, `DisplayStrategy`, `ManifestValidator`, vše v `Contracts`, `Events`, `Testing` a `Push` (kromě `MinishlinkTransport`, `SendWebPush` a `Vapid`), `Tenancy\Tenant`, middleware `CachePage`, `PwaWebMiddleware`, `RememberStandalone` a jejich aliasy |
| Konfigurace | každý klíč v `config/pwa.php` a jeho význam; nové klíče dostanou výchozí hodnotu se starým chováním |
| Artisan | názvy příkazů, argumenty a volby (`pwa:*`) |
| HTTP | routy pod `pwa.route_prefix`, hlavičky požadavků a odpovědí (`X-PWA-*`) |
| Blade | komponenty `<x-pwa::…>`, jejich props a sloty, atributy `data-pwa-*`, CSS třídy `pwa-*` a proměnné `--pwa-*` |
| Prohlížeč | `window.Pwa`, události `pwa:*` a jejich `detail` |
| Service worker | `self.PwaWorker` pro `sw.extend`, zprávy, které přijímá, názvy cache (`pwa-static-*`, `pwa-pages-*`, `pwa-rule-*`, `pwa-shared-*`, `pwa-meta`) |
| Překlady | klíče v `pwa::messages` |

Třídy a metody označené `@internal` (controllery, listenery, třídy Artisan příkazů, ovladače ikon,
pomocné třídy v `Support`) a cokoli `protected` v nich se může změnit v kterékoli verzi. Publikované
views jsou vaše: nová verze může změnit originály, po aktualizaci je porovnejte.

## Co smí minor verze

- přidat klíče configu, metody, události, komponenty, členy klienta a zprávy workeru;
- změnit vnitřní fungování cache service workeru, pokud platí výše uvedené názvy cache a pravidla
  (nová verze workeru se aktivuje sama, viz [Service worker](service-worker.md#verze));
- zvýšit minimální verzi volitelné závislosti (`minishlink/web-push`) v souladu s její vlastní podporou;
- přidat podporu nové verze Laravelu nebo PHP.

Ukončení podpory verze Laravelu nebo PHP, kterou už nepodporují ani její autoři, přijde jen v minor
verzi, oznámí se v changelogu o verzi dřív a nikdy v patch verzi.

## Politika podpory

| Verze | PHP | Laravel | Opravy chyb | Bezpečnostní opravy |
| --- | --- | --- | --- | --- |
| 1.x | 8.2 – 8.5 | 12, 13 | poslední minor | poslední minor |

Zranitelnosti hlaste soukromě podle [SECURITY.md](https://github.com/NyonCode/laravel-pwa/blob/main/SECURITY.md).
