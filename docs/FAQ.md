# FAQ

Quick answers to questions that came up while writing or reviewing
the package. If your question is not here, please
[open an issue](https://github.com/InitPHP/Auth/issues/new) —
documentation fixes are reviewed eagerly.

## General

### What is `initphp/auth` for?

A small library that gives you a uniform `AdapterInterface` over
auth state storage — sessions, signed cookies, or anything custom —
plus a tiny case-insensitive permission helper. Pick an adapter, hand
it a name, get/set/has/remove your auth state.

### What is it NOT for?

- **Authentication itself.** The package stores and retrieves the
  identity you've already verified. Password verification, OAuth
  flows, JWT issuance — those happen in your code, not in this
  library. The [HTTP Basic Auth recipe](Recipe-Basic-Auth.md) shows
  one shape of how to wire an authentication step in.
- **Authorization rules.** `Permission` does membership checks. If you
  need RBAC inheritance, ABAC policies, or row-level security, you
  want a dedicated library.
- **CSRF middleware.** The library is a storage layer, not a router.
  See [Recipe → CSRF Token](Recipe-Csrf-Token.md) for the pattern.
- **Password hashing.** Use `password_hash()` / `password_verify()`.

### Does it have any runtime dependencies?

One: [`initphp/parameterbag`](https://github.com/InitPHP/ParameterBag)
`^2.0`. The session and cookie adapters use it internally as their
in-memory bag. PHP's `ext-json` and `ext-hash` are also required (both
ship with default PHP builds).

Dev tools (PHPUnit, PHPStan, PHP-CS-Fixer) live under `require-dev`.

### Why two storage adapters in one package?

Because the choice between "stateful server" (session) and "stateless
server" (signed cookie) is the single most common variable in real-
world auth deployments — and the two adapters share enough machinery
that putting them in separate packages would be wasteful.

If you want a third option (database, Redis, JWT), implement
[`AdapterInterface`](Adapter-Interface.md). See [Custom
Adapters](Custom-Adapters.md).

## Usage

### Why does `$perm->is('editor')` return `false` when I stored `'Editor'`?

You are on v1. v2 normalizes permissions in the constructor the same
way `push()` does, so `'Editor'` and `'editor'` are the same
permission everywhere. See
[Migration Guide → Permission case-folding](Migration-Guide.md#permission--case-folding-moved-into-the-constructor).

### Why does `Segment::session('auth')` throw `RuntimeException`?

`SessionAdapter` refuses to operate without an active PHP session.
Call `session_start()` (or whatever your framework's session bootstrap
does) before you instantiate the segment.

```php
session_start();
$auth = Segment::session('auth');
```

If you cannot start the session at adapter-construction time, defer
the construction until you can. See
[Session Adapter](Session-Adapter.md#active-session-is-mandatory).

### How do I store more than one segment in one session?

By giving each segment a different name. They live under different
`$_SESSION` keys and do not interfere:

```php
$auth = Segment::session('auth');
$cart = Segment::session('cart');
```

See [Recipe → Multi-Segment Request](Recipe-Multi-Segment.md).

### How big can the cookie get?

Most browsers cap a single cookie at 4096 bytes. The JSON payload
plus the HMAC signature plus the base64url overhead all count.
Keep the payload to ids and tokens — for anything larger, prefer a
session adapter or store the payload server-side and put only the
key in the cookie.

### How do I rotate the cookie salt without logging everyone out?

You cannot, with the shipped adapter as-is — every cookie signed
with the old salt fails the new salt's `hash_equals()`. Two pragmatic
options:

1. **Accept the forced logout.** Run the rotation during a
   maintenance window, watch the brief spike of re-logins, move on.
2. **Run two segments side by side.** Read from both segments at
   the start of every request; write only to the new segment. After
   your cookie TTL has elapsed, retire the old segment.

See [Security → Salt rotation](Security.md#salt-rotation).

### Can I encrypt the cookie payload?

The shipped adapter signs but does not encrypt. If you need
confidentiality (you want to store something the user must not read,
not just something they must not change), layer an authenticated
encryption scheme over the JSON before signing — but at that point
you almost certainly want a [server-side adapter](Session-Adapter.md)
instead. See [Security → The cookie is signed, not encrypted](Security.md#the-cookie-is-signed-not-encrypted).

### Can I use this with Laravel / Symfony / Slim / Mezzio?

Yes. The `AdapterInterface` is plain PHP — nothing in the library
ties it to a framework. The catches are framework-specific:

- **Laravel / Symfony** already provide a session abstraction. You
  can ignore `SessionAdapter` and use yours; or you can use
  `SessionAdapter` and the framework's session middleware will pick
  up the writes.
- **PSR-7 stacks (Slim, Mezzio)** do not use PHP's response header
  buffer. Implement a `CookieWriterInterface` that pushes into the
  PSR-7 `ResponseInterface` instead of calling `setcookie()`. See
  [Cookie Writer → Writing your own writer](Cookie-Writer.md#writing-your-own-writer).

### How do I test code that uses `Segment::cookie()` without touching response headers?

Inject `InMemoryCookieWriter` as the third constructor argument. See
[Testing → Cookie tests with InMemoryCookieWriter](Testing.md#cookie-tests-with-inmemorycookiewriter).

### How do I test code that uses `SessionAdapter`?

Run each test in its own process so `session_status()` resets between
cases. Use `@runTestsInSeparateProcesses` at the class level and a
`setUp()` that calls `session_start()` when the session is inactive.
Full pattern in [Testing → Session tests](Testing.md#session-tests).

### Does the package work with PHP-FPM / mod_php / Swoole / RoadRunner?

`SessionAdapter` and `NullAdapter` are agnostic. `CookieAdapter`'s
default `NativeCookieWriter` calls `setcookie()`, which only works in
SAPIs that buffer response headers (PHP-FPM, mod_php, the built-in
server). For long-running runtimes like Swoole or RoadRunner, plug in
a custom `CookieWriterInterface` that pushes into the request-scoped
response object instead.

### Why does my `$_COOKIE['auth']` show the old value even after `$auth->destroy()`?

PHP populates `$_COOKIE` from the request headers and never updates it
when `setcookie()` runs. `CookieAdapter::destroy()` unsets the key in
`$_COOKIE` for the current request so subsequent reads in the same
request see the cleared state, but the deletion `Set-Cookie` header
only takes effect on the next request.

## Comparisons

### vs. PHP's plain `$_SESSION`?

The library adds:

- An interface so your application code is decoupled from "session vs
  cookie vs database".
- A safe destroy semantics that does not nuke unrelated session keys.
- An adapter that survives without a session at all (signed cookies).

If you only ever need `$_SESSION` and you only ever have one segment
of state, you can absolutely keep using `$_SESSION` directly.

### vs. `delight-im/auth` / `aura/auth` / framework auth components?

Different shape. This library is a **storage layer**. The other
libraries you might be comparing it to are **full authentication
systems** — they handle password hashing, login flows, token issuance,
rate limiting, account recovery. If you want one of those, you want
one of those.

The two combine nicely: use a full auth system for the login flow,
then store the resulting identity in an `initphp/auth` segment.

### vs. JWT?

A signed cookie carries the same idea as a JWT — payload + signature
the server verifies — without the JWT-specific algorithm matrix and
header. If you want JWT-shaped tokens (Bearer headers, `kid`
rotation, `aud` / `iss` claims), use a JWT library. If you want
"signed cookie that holds a user id", use `CookieAdapter`.

## Operational

### How do I add a permission to an already-logged-in user without a re-login?

If the permission list lives in the session segment, update it and
the next request sees the new permissions:

```php
$auth = Segment::session('auth');
$perm = new Permission($auth->get('permissions', []));
$perm->push('editor');
$auth->set('permissions', $perm->getPermissions());
```

If the permission list lives in the cookie segment, do the same — the
cookie is re-issued on the same response.

### How do I "log this user out of every device"?

For session-based auth: regenerate every session id you have. PHP's
session machinery does not make this trivial — you typically need to
keep a separate `user_id → session_id` index (or rely on a session
storage backend that exposes one).

For cookie-based auth: switch to the [Remember-Me
recipe](Recipe-Remember-Me.md) pattern, which keeps a revocation list
keyed by user id, and revoke every row for the user.

### How do I observe failed authentication attempts?

The library is silent — tampered cookies yield an empty bag without
throwing. Instrument the call site:

```php
$auth = Segment::cookie('auth', ['salt' => $secret]);

if (isset($_COOKIE['auth']) && !$auth->has('user_id')) {
    logger()->warning('Auth cookie present but rejected', [
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua'        => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}
```

Repeat-offender IPs are worth alerting on.

### Why is there no built-in throttling / rate-limit?

Because rate-limiting belongs at the edge (web server, CDN) or in a
dedicated middleware. The storage layer is the wrong abstraction to
bolt it onto — every adapter would have to implement it the same way,
and you would still want the edge protection on top.

## Contributing

### I found a bug. Where do I report it?

[github.com/InitPHP/Auth/issues](https://github.com/InitPHP/Auth/issues) —
use the bug template. Include the PHP version (`php -v`), the package
version (`composer show initphp/auth`), and a minimal reproducer.

### I want to add a feature. Where do I start?

Open a [Discussion](https://github.com/orgs/InitPHP/discussions) in
the **Ideas** category first. Most non-trivial features need design
discussion before code makes sense. See the
[org-wide CONTRIBUTING guide](https://github.com/InitPHP/.github/blob/main/CONTRIBUTING.md).

### I have a security report. Where do I send it?

Use **GitHub Private Vulnerability Reporting** from the
[Security tab](https://github.com/InitPHP/Auth/security/advisories/new)
of the repository, or email the address in the
[org-wide SECURITY.md](https://github.com/InitPHP/.github/blob/main/SECURITY.md).
**Do not** open a public issue or Discussion for a security report.

## Where to go next

- [Home](Home.md) — top-level overview.
- [Migration Guide](Migration-Guide.md) — v1 → v2 changes.
- [Recipes](Recipes.md) — end-to-end patterns.
