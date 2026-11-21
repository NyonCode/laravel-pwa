# `ai/` — agent support that ships with the package

This directory is **published**, not scratch notes. It is installed into every application that
requires `nyoncode/laravel-pwa`, and it is what a coding agent working on that application reads.

An agent asked to "make this app installable" or "make the dashboard work offline" otherwise
reconstructs the API from whatever release was in its training data. A reference into `vendor/`
always matches `composer.lock`.

| File | What it is | Who reads it |
|---|---|---|
| [`AGENTS.md`](./AGENTS.md) | The complete public surface in one file: routes, middleware, facade, manifest builder, components, worker rules, client, commands, config, silent mistakes | Any agent, via a block in the application's own `AGENTS.md` |
| [`skills/laravel-pwa/SKILL.md`](./skills/laravel-pwa/SKILL.md) | A Claude Code skill — loaded when the work is about the PWA | Claude Code |
| [`mcp/server.mjs`](./mcp/server.mjs) | An MCP server answering from the installed docs, source and config | Any MCP client |

[`../bin/pwa-ai`](../bin/pwa-ai) wires all three into an application (`install`, `update`,
`status`, `remove`). Derived from `nyoncode/laravel-package-toolkit`'s `package-toolkit-ai`.

## The MCP server

Node 18+, zero dependencies, newline-delimited JSON-RPC 2.0 over stdio.

```bash
node ai/mcp/server.mjs --self-test    # parse everything, print a summary, exit 1 on failure
node ai/mcp/server.mjs                # speak MCP on stdin/stdout
```

| Tool | Source of the answer |
|---|---|
| `search_docs`, `list_docs`, `get_doc` | `docs/*.md` listed in `docs/nav.json`, plus `ai/AGENTS.md` (`agent-guide`) |
| `list_api`, `describe_api` | public methods parsed from `src/Pwa.php`, `src/Manifest/*`, `src/ServiceWorker/*`, `src/Http/Middleware/*` |
| `describe_config` | keys of `config/pwa.php` (one key per line, Pint-formatted) |
| `list_commands` | `$signature` / `$description` of `src/Console/*` |
| `describe_client` | `window.Pwa` members and `emit('…')` events in `resources/js/pwa.js`, plus `docs/client.md` |

It depends on two conventions that degrade it quietly if broken:

- **Pint-formatted PHP**: methods are matched with a regex (`public function name(…)`, optional
  docblock directly above), config keys line by line.
- **`docs/nav.json` is the table of contents**: a page not listed there is not served. The
  self-test fails if a listed page is missing.

## Maintaining this — the rule

A change to a public method, config key, route, middleware, command, client member or event touches
**`docs/` and `ai/AGENTS.md` in the same commit**. Signatures, config defaults, command signatures
and client members are read from source by the server, but prose is not, and nothing in Pest or
PHPStan notices when it goes stale. `tests/Feature/AiTest.php` runs the self-test and checks that
every command, config section and client member is mentioned in `ai/AGENTS.md`.
