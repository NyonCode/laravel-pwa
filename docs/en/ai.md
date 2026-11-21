---
title: AI agents
description: An MCP server, a Claude Code skill and a reference for coding agents, installed with one command and always matching your installed version.
---

# AI agents

An agent that sets up a PWA in your app would otherwise guess the package API from whatever version
was in its training data. That's why the package ships its own agent documentation inside `vendor/`,
so the agent reads the version pinned in your `composer.lock`.

```bash
vendor/bin/pwa-ai install
```

```
Wiring agent support into /home/you/app

  ✓ appended a block to AGENTS.md
  ✓ created .claude/skills/laravel-pwa/SKILL.md
  ✓ registered the server in .mcp.json
```

| Command | What it does |
| --- | --- |
| `install` | adds all three parts; running it again changes nothing |
| `update` | rewrites only what is already installed (safe in `post-update-cmd`) |
| `status` | shows what is installed |
| `remove` | removes exactly what `install` added |

Options: `--dry-run`, `--project=PATH`, `--no-guide`, `--no-skill`, `--no-mcp`.

## Staying current

The `AGENTS.md` block and the `.mcp.json` entry contain only paths into `vendor/`, so
`composer update` keeps them current on its own. The skill is a copy, so add this:

```json
{
    "scripts": {
        "post-update-cmd": ["@php vendor/bin/pwa-ai update"]
    }
}
```

## What gets installed

### Reference in `AGENTS.md`

The block between `<!-- laravel-pwa:start -->` and `<!-- laravel-pwa:end -->` points to
`vendor/nyoncode/laravel-pwa/ai/AGENTS.md`: the complete API, config, middleware, components, client,
commands, rules, and the pitfalls you can't see from the code. `AGENTS.md` is read by Claude Code,
Cursor, Codex, Copilot, Windsurf, Zed and others.

### Claude Code skill

`.claude/skills/laravel-pwa/SKILL.md` loads whenever the work is PWA-related (manifest, service worker,
offline, maintenance mode, installation, push in a PWA).

### MCP server

`.mcp.json` gets a `laravel-pwa` server (`node vendor/nyoncode/laravel-pwa/ai/mcp/server.mjs`).
It needs Node 18+ and has no dependencies.

| Tool | Answers from |
| --- | --- |
| `search_docs` | `docs/*.md` and `ai/AGENTS.md`, returning whole sections |
| `list_docs`, `get_doc` | documentation pages; `get_doc("agent-guide")` returns the full API |
| `list_api`, `describe_api` | public methods from `src/` (Pwa, ManifestBuilder, ServiceWorkerBuilder …) with signatures and docblocks |
| `describe_config` | `config/pwa.php` keys with their defaults and comments |
| `list_commands` | Artisan commands and their signatures from `src/Console` |
| `describe_client` | `window.Pwa` and the `pwa:*` events from `resources/js/pwa.js` |

To check it:

```bash
node vendor/nyoncode/laravel-pwa/ai/mcp/server.mjs --self-test
```

To register it manually in another client (Cursor, Windsurf, Claude Desktop):

```json
{
  "mcpServers": {
    "laravel-pwa": { "command": "node", "args": ["vendor/nyoncode/laravel-pwa/ai/mcp/server.mjs"] }
  }
}
```
