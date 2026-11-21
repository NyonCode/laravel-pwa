# Security policy

## Supported versions

| Version | Security fixes |
| --- | --- |
| 1.x | latest minor release |

## Reporting a vulnerability

Please do not report vulnerabilities in public issues. Email **ondrej@nyoncode.cz** with a
description, the affected version and, if you can, steps to reproduce. You get an answer within
72 hours.

Once a fix is ready, it is released as a patch version and published as a GitHub security advisory
with credit to the reporter, unless you prefer to stay anonymous.

## Scope

The package caches pages and API responses per user in the browser, keeps users signed in, stores
push subscriptions and runs a service worker on your origin. Issues where one user's data reaches
another user, a request reaches a host it should not, or the worker can be made to serve content it
should not, are in scope. See the [security documentation](https://nyoncode.github.io/laravel-pwa/security.html)
for what the package does and deliberately does not do.

Czech: [SECURITY.cs.md](SECURITY.cs.md).
