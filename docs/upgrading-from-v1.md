# Upgrading from v1

v2 ships intentional behaviour changes. Most are bug fixes that have a
visible BC impact; a handful are deliberate hardening of defaults.
Below is the full list, what changes from your side, and how to migrate.

## Requirements

| | v1 | v2 |
| --- | --- | --- |
| PHP | `>=7.4` | `^8.0` (tested on 8.0–8.4) |
| `initphp/parameterbag` | `^1.0` | `^2.0` |

Bump your `composer.json` constraint to `^2.0` and run
`composer update initphp/auth`.

## `Permission` — case-folding moved into the constructor

**v1 bug:** the constructor stored permissions verbatim while
`is()`/`push()`/`remove()` lower-cased the *needle*, so a mixed-case
permission supplied at construction time could never match.

```php
// v1
$perm = new Permission(['Editor']);
$perm->is('editor');  // false
```

**v2 fix:** the constructor normalizes (lower-case + trim) and
deduplicates, the same way `push()` does.

```php
// v2
$perm = new Permission(['Editor']);
$perm->is('editor');  // true
```

**Action:** if you worked around the bug by lower-casing input before
passing it in, that workaround now becomes a no-op (still correct).
Nothing to change.

## `Permission::getPermission()` deprecated

Renamed for plural consistency. The old method survives as a deprecated
alias and will be removed in v3.

```php
$perm->getPermission();   // still works in v2, raises @deprecated notice in IDEs
$perm->getPermissions();  // preferred
```

**Action:** find/replace at your leisure.

## `Permission::$_perms` renamed to `$permissions`

Underscore-prefixed properties were a v1 PSR-12 violation. Anything
that touched `$_perms` directly (subclasses, reflection, serialized
payloads from v1) will need to be updated.

`__sleep()` now lists `permissions`, so v1 serialized blobs cannot be
reinflated under v2 — re-serialize them when you next hydrate.

## `NullAdapter::has()` returns `false`

**v1:** always returned `true`, which combined with `get()` always
returning the default produced the inconsistent pair
`has(x) === true && get(x) === null`.

**v2:** `has()` returns `false` — nothing is ever present in a Null
Object store.

**Action:** verify that no production code relies on the buggy
`has() === true` to satisfy a guard. The kind of guard you wrote was
almost certainly meant to fall through, which is exactly what v2 makes
it do.

## `CookieAdapter` — new wire format

**v1:** `base64(serialize([data, hash]))` with `md5(sha1(...))`.

**v2:** `base64url(json_encode($data)) . "." . hash_hmac('sha256', $json, $salt)`.

Why:

- HMAC + `hash_equals()` instead of a hand-rolled hash with `!=`
  (constant-time comparison, no timing side-channel).
- JSON instead of `serialize()` (no object instantiation path during
  decode, no POP-gadget risk).
- Hash verified **before** the payload is parsed.

**Action:** v2 cannot read v1 cookies — they are silently dropped and
the user is issued a fresh v2 cookie on their next write. Plan for a
quiet logout of everyone holding a v1 cookie. There is no migration
path that would risk running `unserialize()` against attacker bytes.

## `CookieAdapter` — stricter salt

**v1:** minimum 8 characters.

**v2:** minimum **32 bytes** (matches the SHA-256 output length).

```php
// Generate one
echo bin2hex(random_bytes(32));
```

**Action:** if your existing salt is shorter, generate a longer one
and update your environment. (You will need to do this anyway because
the wire format changed.)

## `CookieAdapter` — stricter defaults

| Option | v1 default | v2 default |
| --- | --- | --- |
| `secure` | `false` | `true` |
| `samesite` | `'None'` | `'Lax'` |

`SameSite=None` requires `Secure=true` per the modern cookie spec.
v2 rejects the unsafe combination with `InvalidArgumentException`
instead of silently emitting a cookie the browser will drop.

**Action:** if you were running on plain HTTP in development, opt
back into `secure=false` explicitly **and** drop `SameSite` back to
`Lax`/`Strict`. Production should run on HTTPS.

## `CookieAdapter` — `destroy()` now reuses path/domain

**v1 bug:** the deletion `setcookie()` call only set `expires`, so the
browser refused to delete a cookie originally written with a
non-default path.

**v2 fix:** the deletion reuses `$this->options` and only overrides
`expires`. No action required; cookies that previously refused to
delete will now delete.

## `CookieAdapter` — injectable writer

The constructor gained an optional third argument:

```php
new CookieAdapter(
    string $name,
    array $options = [],
    ?CookieWriterInterface $writer = null,
);
```

Default behaviour is unchanged (`NativeCookieWriter` wraps `setcookie()`).
Tests can inject `InMemoryCookieWriter` to capture calls without
touching response headers.

## `SessionAdapter` — `__call` magic delegation removed

**v1:** `SessionAdapter::__call($name, $args)` forwarded to the
internal `ParameterBag`. Calls like `$adapter->merge([...])` mutated
the bag but never synced back to `$_SESSION` — silent data loss.

**v2:** the magic is gone. Use the documented `get/set/has/remove/collective/destroy`
methods, all of which sync `$_SESSION` after every write.

**Action:** if you reached into bag methods through `__call`, switch
to `$adapter->collective([...])` or to one of the explicit methods.

## `SessionAdapter` — options forwarded to ParameterBag

The constructor's second argument is now forwarded straight to the
underlying `ParameterBag`. The biggest practical effect is that you can
opt into dotted-path access:

```php
$auth = new SessionAdapter('auth', ['isMulti' => true]);
$auth->get('profile.name');
```

In v1 the options array was accepted and ignored.

## `Segment` — new typed factory methods

`Segment::create()` and the constructor still take an `int|string`
adapter (kept for v1 BC), but new code should use the typed factories:

```php
Segment::session($name, $options);
Segment::cookie($name, $options);
Segment::custom($name, $adapterClass, $options);
```

The error messages on misuse are also more helpful — passing an
unknown integer constant or a class that does not extend
`AbstractAdapter` now tells you exactly which case it hit.

## `AdapterInterface` — no longer requires a constructor

**v1:** the interface declared `__construct(string $name, array $options = [])`,
which is a PSR anti-pattern and forced every implementation to take
options through an array even when it wanted to inject a PDO handle
or a Redis client directly.

**v2:** constructors are out of the contract. `Segment::custom()` still
invokes `new YourClass($name, $options)`, so the convention if you want
`Segment` compatibility is unchanged — but you can hand-build adapters
with any constructor signature now.

**Action:** existing adapters that extend `AbstractAdapter` keep
working. Adapters that implemented the interface directly without
extending the abstract can drop the `__construct` declaration if they
want.

## `AbstractAdapter` — fewer abstract methods

v1 redeclared every interface method as `abstract` in the base class
without adding any shared behaviour. v2 keeps only a default
`collective()` that iterates `set()` for adapters that cannot commit
atomically; everything else is satisfied by implementing the interface.

## Tooling

v2 ships with the same dev workflow as the rest of the InitPHP
ecosystem:

```bash
composer install
composer test         # PHPUnit
composer analyse      # PHPStan level 8
composer cs:check     # PHP-CS-Fixer dry-run
composer cs:fix       # PHP-CS-Fixer apply
```

The test suite covers `Permission`, `SessionAdapter`, `CookieAdapter`,
`Segment`, and `NullAdapter`. CI runs on PHP 8.0 through 8.4.
