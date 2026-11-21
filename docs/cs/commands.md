---
title: Příkazy
description: Reference artisan příkazů pwa:* – volby, výstup, návratové kódy a kdy je spouštět.
---

# Příkazy

| Příkaz | Kdy |
| --- | --- |
| `pwa:install` | jednou po `composer require` |
| `pwa:icons` | při změně loga |
| `pwa:build` / `pwa:clear` | při každém nasazení (nebo `optimize` / `optimize:clear`) |
| `pwa:doctor` | po instalaci, v CI a deploy skriptu |
| `pwa:down` | údržba |
| `pwa:disable` / `pwa:enable` | nouzové vypnutí SW |

## pwa:install

```bash
php artisan pwa:install [--no-interaction]
```

Publikuje `config/pwa.php` (tag `pwa::config`) a vypíše další kroky. Při chybě publikování vrací 1.

## pwa:icons

```bash
php artisan pwa:icons {source?} {--background=} {--splash}
```

| Argument / volba | Popis |
| --- | --- |
| `source` | PNG (gd nebo imagick) nebo SVG (jen imagick); bez něj `icons.source` |
| `--background` | barva pozadí maskable a iOS ikony; výchozí `theme.background` |
| `--splash` | vytvoří i úvodní obrazovky iOS pro každý iPhone a iPad (`icons.splash`) |

Vytvoří v `public/{icons.path}`: `icon-{size}.png` pro `icons.sizes`, `maskable-512.png`,
`monochrome-512.png`, `apple-touch-icon.png` (180 px), `favicon-32.png`, ze SVG i `icon.svg`.
Přepisuje existující.

S `--splash` vytvoří i `splash/apple-splash-{šířka}x{výška}.png` pro každou obrazovku
z `AppleDevices::SCREENS` na výšku i na šířku a varianty `-dark`, když je nastavené
`icons.splash.dark_background`. Starší úvodní obrazovky nejdřív smaže, takže značky `<link>`
vždy odpovídají aktuální sadě. Všech 22 velikostí v obou orientacích zabere s GD pár sekund.

## pwa:build

```bash
php artisan pwa:build
```

Sestaví service worker do `{sw.compiled_path}/sw.js` z configu a Vite manifestu. Spouštějte po
`npm run build`. Je napojený na `php artisan optimize`.

```
  INFO  Service worker 1a2b3c4d5e6f built (14 files precached) → bootstrap/cache/pwa/sw.js.
```

Bez Vite manifestu varuje (precache pak obsahuje jen offline stránku). Neplatný Vite manifest = 1.

## pwa:clear

Smaže sestavený SW; `/sw.js` se pak sestavuje za běhu. Napojený na `optimize:clear`.

## pwa:doctor

```bash
php artisan pwa:doctor [--ci]
```

| Kontrola | Úroveň při selhání |
| --- | --- |
| HTTPS v `APP_URL` (mimo local) | chyba |
| `pwa.enabled` | varování |
| nouzové vypnutí neaktivní | chyba |
| manifest: `name` | chyba |
| ikony 192 a 512 | chyba |
| maskable ikona, `apple-touch-icon`, úvodní obrazovky iOS | varování |
| screenshoty: `wide` a `narrow` | varování |
| screenshoty: soubor existuje, `sizes` a `type` mu odpovídají | chyba |
| `start_url` uvnitř `scope` | chyba |
| `public/sw.js` neexistuje | chyba |
| Vite manifest | varování |
| SW sestavený | varování |
| sestavený SW odpovídá configu a buildu | chyba |
| soubory ze `sw.import` existují | chyba (cizí doména = varování) |
| výjimky z údržby | varování |
| session driver není `array` | chyba |
| `pwa.remember` na nějaké routě | varování |
| `pwa.cache` na nějaké routě | varování |
| sdílené úložiště nouzového vypnutí | varování |

Bez `--ci` vrací vždy 0; s `--ci` vrací 1 při jakékoli chybě (varování ne).

## pwa:down

```bash
php artisan pwa:down {--message=} {--until=} {--retry=} {--refresh=} {--secret=} {--with-secret}
```

Viz [Údržba](maintenance.md). Neplatné `--until` = 1, jinak návratový kód `artisan down`.

## pwa:disable / pwa:enable

```bash
php artisan pwa:disable [--force]
php artisan pwa:enable
```

Viz [Nouzové vypnutí](kill-switch.md). Bez `--force` se ptá; zamítnutí = 1.

## php artisan about

Sekce **Laravel PWA**: verze, Enabled, Kill switch, Service worker (compiled / not built), Display.
