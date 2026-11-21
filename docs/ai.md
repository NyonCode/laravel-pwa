---
title: AI agenti
description: MCP server, Claude Code skill a reference pro coding agenty, které se instalují jedním příkazem a odpovídají podle nainstalované verze.
---

# AI agenti

Agent, který v aplikaci nastavuje PWA, by API balíčku hádal podle verze z trénovacích dat. Balíček
proto nese vlastní dokumentaci pro agenty uvnitř `vendor/`, takže agent čte verzi z vašeho
`composer.lock`.

```bash
vendor/bin/pwa-ai install
```

```
Wiring agent support into /home/you/app

  ✓ appended a block to AGENTS.md
  ✓ created .claude/skills/laravel-pwa/SKILL.md
  ✓ registered the server in .mcp.json
```

| Příkaz | Co dělá |
| --- | --- |
| `install` | přidá všechny tři části; opakované spuštění nic nemění |
| `update` | přepíše jen to, co už je nainstalované (bezpečné v `post-update-cmd`) |
| `status` | co je nainstalované |
| `remove` | odstraní přesně to, co `install` přidal |

Volby: `--dry-run`, `--project=PATH`, `--no-guide`, `--no-skill`, `--no-mcp`.

## Aktuálnost

Blok v `AGENTS.md` a záznam v `.mcp.json` obsahují jen cesty do `vendor/`, takže je
`composer update` aktualizuje sám. Skill je kopie, proto:

```json
{
    "scripts": {
        "post-update-cmd": ["@php vendor/bin/pwa-ai update"]
    }
}
```

## Co se nainstaluje

### Reference v `AGENTS.md`

Blok mezi `<!-- laravel-pwa:start -->` a `<!-- laravel-pwa:end -->` odkazuje na
`vendor/nyoncode/laravel-pwa/ai/AGENTS.md`: kompletní API, config, middleware, komponenty, klient,
příkazy, pravidla a chyby, které nejsou vidět. `AGENTS.md` čtou Claude Code, Cursor, Codex,
Copilot, Windsurf, Zed a další.

### Claude Code skill

`.claude/skills/laravel-pwa/SKILL.md` se načte, když práce souvisí s PWA (manifest, service worker,
offline, údržba, instalace, push v PWA).

### MCP server

`.mcp.json` dostane server `laravel-pwa` (`node vendor/nyoncode/laravel-pwa/ai/mcp/server.mjs`).
Node 18+, bez závislostí.

| Nástroj | Odpovídá z |
| --- | --- |
| `search_docs` | `docs/*.md` a `ai/AGENTS.md` – vrací celé sekce |
| `list_docs`, `get_doc` | stránky dokumentace; `get_doc("agent-guide")` = celé API |
| `list_api`, `describe_api` | veřejné metody ze `src/` (Pwa, ManifestBuilder, ServiceWorkerBuilder …) se signaturou a docblockem |
| `describe_config` | klíče `config/pwa.php` s výchozí hodnotou a komentářem |
| `list_commands` | artisan příkazy a jejich signatury ze `src/Console` |
| `describe_client` | `window.Pwa` a události `pwa:*` z `resources/js/pwa.js` |

Kontrola:

```bash
node vendor/nyoncode/laravel-pwa/ai/mcp/server.mjs --self-test
```

Ruční registrace v jiném klientovi (Cursor, Windsurf, Claude Desktop):

```json
{
  "mcpServers": {
    "laravel-pwa": { "command": "node", "args": ["vendor/nyoncode/laravel-pwa/ai/mcp/server.mjs"] }
  }
}
```
