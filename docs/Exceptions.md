# Exceptions

The package throws SPL exception types; there are no library-specific
exception classes to learn. This page lists every failure mode and
the class it surfaces as.

```
\InvalidArgumentException        ← bad configuration, caught early
\RuntimeException                ← invariant broken at runtime
\BadMethodCallException          ← magic accessor on Permission
\Error                           ← undefined-method on Segment::__call
```

## InvalidArgumentException

Raised from constructors and factory methods when the **configuration**
is invalid. These all surface before any I/O happens.

| Trigger | Origin | Message |
| --- | --- | --- |
| `Segment` with an unknown int adapter constant | `Segment::resolveAdapter` | `Unknown adapter constant: 999. Expected ADAPTER_SESSION (0) or ADAPTER_COOKIE (1).` |
| `Segment` with a class that does not exist | `Segment::resolveAdapter` | `Adapter class "App\No\Such\Class" does not exist.` |
| `Segment` with a class that does not extend `AbstractAdapter` | `Segment::resolveAdapter` | `Adapter class "stdClass" must extend InitPHP\Auth\AbstractAdapter.` |
| `Segment` with an adapter argument that is neither int nor string | `Segment::resolveAdapter` | `$adapter must be one of the ADAPTER_* constants or a class name that extends InitPHP\Auth\AbstractAdapter.` |
| `CookieAdapter` with a missing / non-string / short `salt` | `CookieAdapter::__construct` | `A "salt" of at least 32 bytes must be supplied. Generate one with bin2hex(random_bytes(32)).` |
| `CookieAdapter` with `samesite='None'` + `secure=false` | `CookieAdapter::__construct` | `SameSite=None requires the cookie to be marked Secure.` |

### Catching

```php
use InitPHP\Auth\Segment;

try {
    $auth = Segment::cookie('auth', ['salt' => 'too-short']);
} catch (\InvalidArgumentException $e) {
    // Surface the typo to the operator and bail.
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
```

## RuntimeException

Raised when a runtime invariant has been broken — typically because
the caller has destroyed the adapter and is still using it, or because
the session is not active.

| Trigger | Origin | Message |
| --- | --- | --- |
| `SessionAdapter` constructed with no active session | `SessionAdapter::__construct` | `Sessions must be started.` |
| Read / write on a destroyed `SessionAdapter` | `SessionAdapter::getBag` | `Sessions were destroyed or not created at all.` |
| Read / write on a destroyed `CookieAdapter` | `CookieAdapter::getBag` | `The cookie has been destroyed or not created at all.` |
| `CookieAdapter::set`/`collective` with non-encodable JSON payload | `CookieAdapter::encoder` | `Failed to encode auth payload as JSON: ...` (chains the underlying `JsonException`) |

### Catching

```php
use InitPHP\Auth\SessionAdapter;

try {
    $auth = new SessionAdapter('auth');
} catch (\RuntimeException $e) {
    // Whoever invoked us forgot to start the session.
    session_start();
    $auth = new SessionAdapter('auth');
}
```

## BadMethodCallException

Raised by `Permission::__call()` when the called method name does not
start with `is_`:

```php
$perm->doSomething();
// BadMethodCallException: Method InitPHP\Auth\Permission::doSomething() does not exist.
```

The `is_*` accessors themselves do not throw — an unknown permission
just returns `false`:

```php
$perm->is_admin();      // true / false
$perm->is_();           // false (empty suffix)
```

## Error (undefined method)

`Segment::__call()` forwards verbatim to the underlying adapter, so a
method that does not exist on the adapter raises the standard PHP
"Call to undefined method" `\Error`:

```php
$segment = Segment::session('auth');
$segment->totallyMadeUp();
// Error: Call to undefined method InitPHP\Auth\SessionAdapter::totallyMadeUp()
```

This is intentional — it surfaces typos at the call site instead of
swallowing them. Prefer the explicit interface methods
(`get/set/has/remove/collective/destroy`) when you can.

## Forwarded exceptions from `initphp/parameterbag`

`SessionAdapter` and `CookieAdapter` hold an internal
[`ParameterBag`](https://github.com/InitPHP/ParameterBag) instance. If
you pass unknown bag options through the adapter constructor, the bag
will throw `ParameterBagInvalidArgumentException`:

```php
new SessionAdapter('auth', ['notAnOption' => true]);
// ParameterBagInvalidArgumentException:
//   "Unknown ParameterBag option(s): notAnOption.
//    Known options: isMulti, separator, caseInsensitive."
```

`ParameterBagInvalidArgumentException` extends `\InvalidArgumentException`,
so existing `catch (\InvalidArgumentException $e)` blocks still work.

## Exception hierarchy at a glance

```
\Throwable
├── \Exception
│   ├── \InvalidArgumentException
│   │   └── InitPHP\ParameterBag\Exception\ParameterBagInvalidArgumentException
│   ├── \RuntimeException
│   └── \BadMethodCallException
└── \Error
```

## Where to go next

- [Adapter Interface](Adapter-Interface.md) — per-method documentation,
  including which methods declare `@throws`.
- [Security](Security.md) — the threat model behind the configuration
  guards.
- [Migration Guide](Migration-Guide.md) — v1 → v2 exception changes
  (mostly: more guards, fewer silent failures).
