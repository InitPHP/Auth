# Quick Start

A five-minute tour of the public surface. Every snippet runs as-is
against the released package.

## Permissions

The `Permission` class is a tiny, dependency-free permission set.
Case-insensitive on the way in **and** on the way out:

```php
use InitPHP\Auth\Permission;

$perm = new Permission(['Editor', 'POST_LIST', 'post_edit']);

$perm->is('editor');             // true
$perm->is('admin');              // false
$perm->is('viewer', 'editor');   // true — any match wins

$perm->push('user', 'Editor');   // returns 1 (the duplicate is skipped)
$perm->remove('post_edit');      // returns 1

$perm->getPermissions();
// ['editor', 'post_list', 'user']
```

See [Permissions](Permissions.md) for the full API, including the magic
`$perm->is_admin` accessors and serialization rules.

## A session-backed segment

A `Segment` is a named slice of auth state. The session-backed
implementation stores everything under `$_SESSION[$name]`:

```php
use InitPHP\Auth\Segment;

session_start();

$auth = Segment::session('auth');
$auth->set('user_id', 42)->set('role', 'editor');

$auth->get('user_id');           // 42
$auth->has('role');              // true
$_SESSION['auth'];               // ['user_id' => 42, 'role' => 'editor']

$auth->destroy();                // unsets $_SESSION['auth']
```

See [Session Adapter](Session-Adapter.md) for the full lifecycle and the
options forwarded to the internal `ParameterBag`.

## A signed-cookie segment

The cookie-backed implementation stores everything inside a signed,
JSON-encoded cookie. The signature is HMAC-SHA256 and is verified
**before** the JSON is decoded, so a forged or tampered cookie never
reaches the parser:

```php
use InitPHP\Auth\Segment;

$auth = Segment::cookie('auth', [
    'salt'   => $_ENV['AUTH_COOKIE_SECRET'], // 32+ bytes, env-loaded
    'path'   => '/',
    'domain' => 'example.com',
]);

$auth->set('user_id', 42);
$auth->get('user_id');           // 42 (on the next request, after the cookie round-trips)

$auth->destroy();                // emits a deletion cookie with matching path/domain
```

See [Cookie Adapter](Cookie-Adapter.md) for the wire format, salt
generation, and the `SameSite`/`Secure` defaults.

## Picking an adapter

| Adapter | Pick when |
| --- | --- |
| [`SessionAdapter`](Session-Adapter.md) | You already use PHP sessions and trust them to live as long as the user does. |
| [`CookieAdapter`](Cookie-Adapter.md) | You want a stateless server, or you want auth state to survive a session restart. The cookie is signed but **not** encrypted — do not put secrets in it. |
| [Custom](Custom-Adapters.md) | You want to keep the state in a database, Redis, JWT, or anywhere else. Implement [`AdapterInterface`](Adapter-Interface.md) (or extend `AbstractAdapter`). |
| [`NullAdapter`](Null-Adapter.md) | Tests, CLI scripts, feature flags — a drop-in that throws nothing and stores nothing. |

The `Segment` factory methods mirror those:

```php
Segment::session('auth');
Segment::cookie('auth', ['salt' => $secret]);
Segment::custom('auth', App\Auth\DatabaseAdapter::class, [...]);
```

The legacy generic factory and constructor are kept for v1 callers:

```php
Segment::create('auth', Segment::ADAPTER_SESSION);
new Segment('auth', Segment::ADAPTER_COOKIE, ['salt' => $secret]);
new Segment('auth', App\Auth\DatabaseAdapter::class);
```

## Chaining and atomic writes

`set()`, `collective()`, and `remove()` return `$this`, so writes chain
naturally. Use `collective()` for bulk writes — the cookie adapter, in
particular, emits one `Set-Cookie` header per `collective()` call
instead of one per `set()`:

```php
$auth->collective([
    'user_id' => 42,
    'role'    => 'editor',
    'logged_at' => time(),
])->set('csrf', bin2hex(random_bytes(16)));
```

## Reaching the underlying adapter

The `Segment` facade implements `AdapterInterface` itself and forwards
the six contract methods. For implementation-specific calls (a custom
adapter that exposes `refreshToken()`, say), use the `adapter()` escape
hatch or rely on the `__call` delegation:

```php
$segment->adapter();             // returns the AdapterInterface instance
$segment->refreshToken('exp');   // delegated through __call
```

## Where to go next

- [Permissions](Permissions.md) — full `Permission` API, magic accessors,
  serialization.
- [Segment](Segment.md) — factory comparison, proxy methods, BC notes.
- [Adapter Interface](Adapter-Interface.md) — contract you implement to
  plug in a custom store.
- [Configuration](Configuration.md) — every option, with examples.
- [API Reference](API-Reference.md) — exhaustive class member list.
