# Permissions

`InitPHP\Auth\Permission` is a small, dependency-free set of named
permissions. It does case-insensitive membership checks, deduplicates
on insertion, and exposes magic accessors that read well in templates.

The class is intentionally not coupled to any adapter — you can stash
the underlying permission list in a `SessionAdapter`, a `CookieAdapter`,
your own database, or anywhere else.

## Constructing a permission set

```php
use InitPHP\Auth\Permission;

new Permission();                                    // empty
new Permission(['Editor', 'POST_LIST', 'post_edit']);
```

The constructor:

1. Runs each entry through the [normalization pipeline](#normalization-rules).
2. Silently skips non-string values.
3. Drops duplicates after normalization.

```php
$perm = new Permission(['Editor', 'editor', 'EDITOR']);
$perm->getPermissions();   // ['editor']

$perm = new Permission(['admin', 42, null, ['nested'], 'editor']);
$perm->getPermissions();   // ['admin', 'editor']  — non-strings dropped
```

> **v1 footgun, fixed:** in v1 the constructor stored permissions
> verbatim while `is()`/`push()`/`remove()` lower-cased the *needle*.
> A mixed-case permission supplied at construction time could never
> match. v2 normalizes in the constructor the same way `push()` does.
> See the [Migration Guide](Migration-Guide.md#permission--case-folding-moved-into-the-constructor).

## Querying membership: `is`

```php
$perm = new Permission(['admin', 'editor']);

$perm->is('admin');              // true
$perm->is('viewer');             // false
$perm->is('viewer', 'editor');   // true — any match wins
$perm->is('viewer', 'guest');    // false
```

`is()` is **any-match**, not all-match. To require every name, call it
per name and combine:

```php
$perm->is('admin') && $perm->is('editor');
```

## Adding & removing: `push`, `remove`

Both methods report the number of names actually changed:

```php
$perm = new Permission(['admin']);

$perm->push('editor', 'viewer');   // returns 2
$perm->push('admin', 'EDITOR');    // returns 0 — both already present (case-insensitive)

$perm->remove('viewer', 'guest');  // returns 1 — viewer removed, guest absent
$perm->remove('viewer');           // returns 0 — already gone
```

`remove()` reindexes the internal list after deletion, so the
`list<string>` invariant holds:

```php
$perm = new Permission(['admin', 'editor', 'viewer']);
$perm->remove('editor');
$perm->getPermissions();   // ['admin', 'viewer']  — keys 0,1 not 0,2
```

> **v1 bug, fixed:** v1 left a hole in the array after `unset()`,
> which broke JSON encoding of `getPermission()` output.

## Snapshotting the set: `getPermissions`

```php
$perm = new Permission(['Editor', 'viewer']);
$perm->getPermissions();   // ['editor', 'viewer']
```

The returned list is **already normalized** (lower-cased, trimmed). It
is also a `list<string>` (0-indexed, sequential keys), so it round-trips
cleanly through `json_encode()` / `json_decode()`.

The v1 alias `getPermission()` survives as a deprecated shim and will
be removed in v3:

```php
$perm->getPermission();    // works, but @deprecated
$perm->getPermissions();   // preferred
```

## Magic accessors

| Expression | Equivalent to |
| --- | --- |
| `$perm->is_admin()` | `$perm->is('admin')` |
| `isset($perm->admin)` | `$perm->is('admin')` |
| `isset($perm->is_admin)` | `$perm->is('admin')` (the `is_` prefix is stripped) |
| `unset($perm->is_admin)` | `$perm->remove('admin')` |

These are convenient inside templates (Twig, Blade, plain PHP). In code
that runs through an IDE or PHPStan, prefer the explicit methods so
auto-completion and static analysis keep working.

A call that does not start with `is_` raises `BadMethodCallException`:

```php
$perm->doSomething();
// BadMethodCallException: Method InitPHP\Auth\Permission::doSomething() does not exist.
```

The same happens for an empty-prefix call:

```php
$perm->is_();   // false (the suffix is empty, no permission to check)
```

## Normalization rules

Every name — supplied at construction time, to `is()`, or to `push()` /
`remove()` — passes through the same internal pipeline:

1. `strtolower()`
2. `trim()`

So `'  Admin  '`, `'admin'`, and `'ADMIN'` all refer to the same
permission. The pipeline runs once on the way in and never again — the
internal list always holds the normalized form.

## Serializing a permission set

`__sleep()` keeps only the permission list, so it is safe to drop a
`Permission` straight into `$_SESSION` or any other PHP-`serialize()`
sink:

```php
$_SESSION['perm'] = serialize(new Permission(['Editor', 'viewer']));

// later, in another request
$perm = unserialize($_SESSION['perm']);
$perm->is('editor');   // true
```

> The serialized blob only contains the `permissions` property. Any
> subclass state you add must be declared on `__sleep()` overrides; the
> base class will not pick it up automatically.

## Common mistakes

- **Forgetting that case-folding happens everywhere.** You do not need
  to lower-case names yourself before passing them to `is()`,
  `push()`, or the constructor. v2 takes care of it.
- **Reading magic accessors in static analysis.** PHPStan and Psalm
  cannot see the `is_*` methods. Use `$perm->is('admin')` instead in
  code that needs to type-check.
- **Treating `is()` as all-match.** It is **any-match**. Combine calls
  with `&&` when you need conjunction.
- **Trying to subclass and add behaviour without overriding `__sleep`.**
  If you add new properties they must be added to the `__sleep()`
  array, or they will not survive a serialize/unserialize cycle.

## Where to go next

- [Segment](Segment.md) — store the permission list alongside the user id
  in an adapter-backed segment.
- [Recipes → Multi-Segment](Recipe-Multi-Segment.md) — auth, cart, and
  CSRF segments side by side.
