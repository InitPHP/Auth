# Documentation

Developer documentation for the `initphp/auth` package. The project
[README](../README.md) gives a one-page overview; this directory goes
deeper.

## Index

- [Getting started](getting-started.md) — install, instantiate, and
  read your first segment.
- [Permissions](permissions.md) — case-insensitive permission set,
  magic accessors, serialization.
- **Adapters**
  - [SessionAdapter](adapters/session.md) — `$_SESSION`-backed storage.
  - [CookieAdapter](adapters/cookie.md) — signed-cookie storage,
    salt generation, `SameSite`/`Secure` guidance, custom writers.
  - [Custom adapters](adapters/custom.md) — implement
    `AdapterInterface` for databases, Redis, JWT, anything.
  - [NullAdapter](adapters/null.md) — no-op adapter for tests and
    feature flags.
- **Recipes**
  - [Multiple segments per request](recipes/multi-segment.md) — auth,
    cart, and CSRF state side by side.
  - [Basic-auth credential cache](recipes/basic-auth-example.md) — the
    PDO-backed example from the v1 README, rewritten without SQL
    injection.
- [Upgrading from v1](upgrading-from-v1.md) — BC notes for v2.

## How to read these docs

Every page is structured as **Goal → Working example → Expected output
→ Common mistakes**. Snippets are copy-paste ready against the released
package; outputs were generated against the test suite.
