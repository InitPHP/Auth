# Session Adapter

`InitPHP\Auth\SessionAdapter` stores auth state under a single key
inside `$_SESSION`. It is the backing store for
[`Segment::session()`](Segment.md#factory-methods).

## When to pick it

- You already rely on PHP sessions and trust them to live as long as
  the user does.
- You want server-side state — the client only sees the session
  cookie, not the payload.
- You can afford the per-request `session_start()` overhead and any
  locking it implies on your save handler.

If you need a stateless server, prefer [`CookieAdapter`](Cookie-Adapter.md).

## Basic usage

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

The state lives at `$_SESSION[$name]` — `'auth'` is the segment name
you passed to the factory. Multiple segments coexist under different
names:

```php
$auth = Segment::session('auth');
$cart = Segment::session('cart');

$auth->set('user_id', 42);
$cart->set('items', 3);

$_SESSION;
// [
//     'auth' => ['user_id' => 42],
//     'cart' => ['items'   => 3 ],
// ]
```

## Active session is mandatory

The constructor refuses to operate against an inactive session because
every subsequent write would silently disappear:

```php
// session_status() === PHP_SESSION_NONE
new SessionAdapter('auth');
// RuntimeException: Sessions must be started.
```

Call `session_start()` (or whatever framework hook does it) before
instantiating the adapter.

## Constructor options

The second constructor argument is forwarded straight to the internal
[`ParameterBag`](https://github.com/InitPHP/ParameterBag/wiki/Configuration).
The useful knobs are:

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `isMulti` | `bool` | `false` | Enables dotted-path access (`$auth->get('user.profile.name')`). |
| `separator` | `string` | `'.'` | Path delimiter when `isMulti` is on. |
| `caseInsensitive` | `bool` | `false` | Folds every key to lower-case on storage and lookup. |

If you pass an empty array, `['isMulti' => false]` is applied as the
default.

```php
$_SESSION['auth'] = ['db' => ['host' => 'localhost', 'port' => 3306]];

$auth = new SessionAdapter('auth', ['isMulti' => true]);
$auth->get('db.host');           // 'localhost'
$auth->get('db.port');           // 3306
```

## Lifecycle

| Call | Effect on `$_SESSION` |
| --- | --- |
| `get($k, $default)` | None — pure read. |
| `set($k, $v)` | Updates the in-memory bag, then re-writes `$_SESSION[$name]`. |
| `collective([...])` | Same, in one operation (single `$_SESSION` write). |
| `has($k)` | None — pure read. |
| `remove(...$k)` | Drops the keys, then re-syncs `$_SESSION[$name]`. |
| `destroy()` | `unset($_SESSION[$name])`. Returns `true` if the slot existed, `false` if not. |

After `destroy()`, any read or write on the adapter raises
`\RuntimeException`. Create a fresh adapter if you need to start over.

## Reading existing data

The constructor hydrates the bag from `$_SESSION[$name]` if anything is
already there:

```php
$_SESSION['auth'] = ['user_id' => 99, 'role' => 'admin'];

$auth = new SessionAdapter('auth');
$auth->get('user_id');   // 99
$auth->get('role');      // 'admin'
```

This makes it safe to instantiate the adapter on every request — the
existing session payload is honoured, and writes are layered on top.

## Cohabitation with raw `$_SESSION` access

The adapter only owns its own slot. Anything you put outside
`$_SESSION[$name]` is untouched:

```php
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$auth = Segment::session('auth');
$auth->destroy();

$_SESSION['csrf_token'];   // still there
```

That property is what makes it safe to have multiple segments and ad-hoc
session keys in the same request — see [Multi-Segment
Request](Recipe-Multi-Segment.md) for the full pattern.

## Common mistakes

- **Calling `session_destroy()` instead of `$auth->destroy()`.** The
  adapter only touches its own slot — that is the point of segmenting
  the session. `session_destroy()` would wipe every segment plus any
  unrelated state PHP holds for the user.
- **Sharing one `SessionAdapter` instance across forks/queues.** PHP
  sessions are tied to the current request. Move state through your
  job payload, not through the session bag.
- **Forgetting `session_start()` when running CLI tests.** The CLI SAPI
  does not seed `session.save_path`; supply it via `ini_set()` or a
  `phpunit.xml` `<ini>` entry — see [Testing](Testing.md#session-tests).
- **Setting an absurdly large value.** PHP serializes the entire
  `$_SESSION[$name]` slot on every write. For multi-kilobyte payloads,
  prefer a database adapter or push large data into a separate store.

## Where to go next

- [Cookie Adapter](Cookie-Adapter.md) — if you need a stateless server.
- [Custom Adapters](Custom-Adapters.md) — to back the segment with a
  database or Redis.
- [Recipes → Multi-Segment](Recipe-Multi-Segment.md) — auth, cart, CSRF
  side by side.
- [API Reference](API-Reference.md#sessionadapter) — full method
  signatures.
