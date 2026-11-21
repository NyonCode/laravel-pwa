# Contributing

Thanks for helping. Bug reports, documentation fixes and pull requests are all welcome.

## Setup

```bash
git clone https://github.com/NyonCode/laravel-pwa.git && cd laravel-pwa
composer install
npm install
npx playwright install chromium firefox   # only for the browser tests
```

## Checks

| Command | What it runs |
| --- | --- |
| `composer test` | Pest (PHP unit and feature tests) |
| `composer test:coverage` | Pest with coverage, fails below 90 % (needs pcov) |
| `composer check` | Pint, Rector, PHPStan, Pest and the MCP self-test |
| `npm run test:js` | Service worker and client unit tests (`node:test`) |
| `npm run lint:js` | ESLint |
| `npm run test:e2e` | Playwright against the workbench app (starts `testbench serve`) |

Run a single test with `vendor/bin/pest --filter="name"` or `node --test tests/js/sw/runtime.test.mjs`.
`vendor/bin/testbench serve` starts the workbench demo app on http://127.0.0.1:8000.

## Rules

- Every behaviour change comes with a test that fails without it.
- Public changes (config keys, methods, routes, events, client members) update `docs/en`, `docs/cs`
  and `ai/AGENTS.md` in the same pull request. `tests/Feature/AiTest.php` checks part of this.
- HTML is never cached without `X-PWA-Cache: allow`, and caches stay separated per user.
- Never send `Clear-Site-Data: "storage"`: it unregisters the worker and drops push subscriptions.
- Code is formatted with Pint; the bundled MCP server parses the source and relies on it.
- Add an entry to `CHANGELOG.md` under `[Unreleased]`.

## Security

Please report vulnerabilities privately, see [SECURITY.md](SECURITY.md).
