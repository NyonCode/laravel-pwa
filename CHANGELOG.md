# Changelog

## [Unreleased]

## [0.1.1] - 2026-11-21

### Bezpečnost
- Service worker čte identitu uživatele (`X-PWA-User`, `X-PWA-Logout`) z každé odpovědi vlastní
  domény, ne jen z navigací. Dřív po XHR odhlášení a přihlášení jiného uživatele (Inertia,
  Livewire) na stejném zařízení zůstala data z `cache.api` předchozího uživatele čitelná.
  - fetch/XHR na cesty z `cache.never_cache` (např. `/login`, `/logout`) jdou přes SW jen kvůli
    identitě, dál se nic neukládá ani neřadí do fronty; mutace se čtou i s `offline.queue = false`,
  - osobní cache přijme jen odpovědi svého uživatele,
  - hlavičky identity z cizích domén (i po přesměrování) a neplatné hodnoty se ignorují,
  - klient předá SW identitu z každého keep-alive (zpráva `IDENTITY`).

### Opraveno
- Zpráva `CLEAR_USER` nastaví i aktuálního uživatele v paměti SW, takže data stažená hned potom
  se neuloží pod předchozího uživatele.

## [0.1.0] - 2026-11-21

### Přidáno
- Dynamický manifest se strategiemi zobrazení `override`, `adaptive`, `fixed` a fluent API.
- Service worker: precache z Vite buildu, cache stránek jen se souhlasem serveru, oddělení
  uživatelů, fronta offline akcí, rozlišení údržby (503), telemetrie chyb, `sw.import`.
- Offline obrazovka a obrazovka údržby (`pwa:down`) s CSP nonce a hashi.
- Trvalé přihlášení: `pwa.remember`, keep-alive `/pwa/session`, obnova CSRF, `X-PWA-Logout`.
- Komponenty `<x-pwa::head/>`, `<x-pwa::nav/>`, `<x-pwa::install/>`.
- Příkazy `pwa:install`, `pwa:build`, `pwa:clear`, `pwa:icons`, `pwa:down`, `pwa:disable`,
  `pwa:enable`, `pwa:doctor`.
- Adaptéry pro Livewire 3 a Inertia (včetně opakování 419 přes `window.axios`).
- AI podpora: `ai/AGENTS.md`, Claude Code skill `laravel-pwa`, MCP server bez závislostí
  (`search_docs`, `get_doc`, `describe_api`, `describe_config`, `list_commands`, `describe_client` …)
  a instalátor `vendor/bin/pwa-ai`.
- Podrobná dokumentace v `docs/` (21 stránek, pořadí v `docs/nav.json`).
