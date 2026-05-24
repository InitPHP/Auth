# Permissions

`InitPHP\Auth\Permission` is a small, dependency-free set of named
permissions. It does case-insensitive membership checks, deduplicates
on insertion, and exposes a couple of magic accessors that read well in
templates.

## Goal

You have a list of permission strings attached to a user. You want to
ask "does this user have *editor* rights?" without worrying about whether
the database row stored the value as `Editor`, `EDITOR`, or `editor`.

## Working example

```php
use InitPHP\Auth\Permission;

$perm = new Permission(['Editor', 'POST_LIST', 'post_edit']);

$perm->is('editor');             // true
$perm->is('admin');              // false
$perm->is('viewer', 'editor');   // true — any match wins

$perm->push('user', 'Editor');   // returns 1 (the duplicate is skipped)
$perm->remove('post_edit');      // returns 1

print_r($perm->getPermissions());
```

Expected output:

```
Array
(
    [0] => editor
    [1] => post_list
    [2] => user
)
```

## API reference

| Method | Returns | Notes |
| --- | --- | --- |
| `__construct(array $permissions = [])` | — | Non-string entries are silently skipped. Duplicates are dropped post-normalization. |
| `is(string ...$names): bool` | `bool` | True when **any** name is present. |
| `push(string ...$names): int` | `int` | Number of names actually inserted (already-present names return 0). |
| `remove(string ...$names): int` | `int` | Number of names actually removed. The list is reindexed afterwards. |
| `getPermissions(): list<string>` | `list<string>` | Snapshot of the current set (already lower-cased and trimmed). |
| `getPermission(): list<string>` | `list<string>` | **Deprecated** v1 alias. Use `getPermissions()`. |

### Normalization rules

Every name — whether supplied at construction time, to `is()`, or to
`push()` / `remove()` — passes through the same internal pipeline:

1. `strtolower()`
2. `trim()`

So `'  Admin  '`, `'admin'`, and `'ADMIN'` all refer to the same
permission.

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
$perm->doSomething();  // BadMethodCallException
```

## Serializing the permission set

`__sleep()` keeps only the permission list, so it is safe to drop a
`Permission` straight into `$_SESSION`:

```php
$_SESSION['perm'] = serialize(new Permission(['Editor', 'viewer']));

// later, in another request
$perm = unserialize($_SESSION['perm']);
$perm->is('editor');  // true
```

## Common mistakes

- **Forgetting normalization happens in the constructor.** In v1 the
  constructor stored values verbatim and only `push()` / `is()`
  lower-cased the *needle*, which meant a permission supplied at
  construction time with mixed case could never match. v2 fixes this:
  the constructor uses the same normalization as `push()`. If you are
  porting a v1 codebase, you do not need to lower-case input yourself
  any more.
- **Treating `is()` like a "has all" check.** `is('editor', 'admin')`
  is **any-match**, not all-match. To require every name, call `is()`
  per name and combine with `&&`.
- **Reading from the magic accessors in static analysis.** PHPStan and
  Psalm cannot see the `is_*` methods. Use `$perm->is('admin')`
  instead in code that needs to type-check.
