# InitPHP Auth

A small PHP authentication & authorization library with pluggable storage
adapters (session, signed cookie, or custom) and a tiny case-insensitive
permission set.

[![Latest Stable Version](https://poser.pugx.org/initphp/auth/v)](https://packagist.org/packages/initphp/auth)
[![Total Downloads](https://poser.pugx.org/initphp/auth/downloads)](https://packagist.org/packages/initphp/auth)
[![CI](https://github.com/InitPHP/Auth/actions/workflows/ci.yml/badge.svg)](https://github.com/InitPHP/Auth/actions/workflows/ci.yml)
[![License](https://poser.pugx.org/initphp/auth/license)](https://packagist.org/packages/initphp/auth)
[![PHP Version Require](https://poser.pugx.org/initphp/auth/require/php)](https://packagist.org/packages/initphp/auth)

---

## Features

- **Pluggable storage** — pick `SessionAdapter`, `CookieAdapter`, or roll
  your own by implementing `AdapterInterface`.
- **Signed cookies** — JSON payload sealed with constant-time HMAC-SHA256;
  tampered values are dropped before decoding ever runs.
- **Strict cookie defaults** — `Secure`, `SameSite=Lax`, `HttpOnly`, and
  refusal of the unsafe `SameSite=None + Secure=false` combination.
- **Testable** — inject a `CookieWriterInterface` to capture every
  `setcookie()` call in unit tests instead of touching response headers.
- **Tiny permission set** — `Permission` does case-insensitive membership
  checks and ships magic accessors (`$perm->is_admin`).
- **Honest contracts** — typed properties, return types, `@throws` on
  every implementation-defined exception, PHPStan level 8 clean.

## Requirements

- PHP 8.0 or later (tested on 8.0 – 8.4)
- ext-json, ext-hash (both bundled with default PHP builds)
- [`initphp/parameterbag`](https://github.com/InitPHP/ParameterBag) `^2.0`

## Installation

```bash
composer require initphp/auth
```

## Quick start

### Session-backed auth

```php
use InitPHP\Auth\Segment;

session_start();

$auth = Segment::session('auth');
$auth->set('user_id', 42)->set('role', 'editor');

if ($auth->has('user_id')) {
    $user = loadUser($auth->get('user_id'));
}

$auth->destroy(); // unsets $_SESSION['auth']
```

### Signed-cookie auth

```php
use InitPHP\Auth\Segment;

$auth = Segment::cookie('auth', [
    // 32+ byte secret. Generate with bin2hex(random_bytes(32)) and
    // load it from configuration — never hard-code it in source.
    'salt'   => $_ENV['AUTH_COOKIE_SECRET'],
    'path'   => '/',
    'domain' => 'example.com',
]);

$auth->set('user_id', 42);
echo $auth->get('user_id'); // 42

$auth->destroy(); // emits a deletion cookie with matching path/domain
```

### Permissions

```php
use InitPHP\Auth\Permission;

// Comparison is case-insensitive: 'Editor', 'EDITOR', and 'editor'
// are the same permission. The constructor normalizes its input the
// same way push() and remove() do.
$perm = new Permission(['Editor', 'post_list', 'post_edit']);

if ($perm->is('editor')) {
    $perm->push('user');         // returns 1
    $perm->remove('post_edit');  // returns 1
}

$perm->is('admin', 'editor');    // true if any of the names is present
isset($perm->is_admin);          // magic accessor for templates
```

## Public API

### `Segment`

| Method | Purpose |
| --- | --- |
| `Segment::session(string $name, array $options = []): self` | Build a segment backed by `$_SESSION`. |
| `Segment::cookie(string $name, array $options): self` | Build a segment backed by a signed cookie (`salt` required). |
| `Segment::custom(string $name, class-string $adapterClass, array $options = []): self` | Build a segment backed by your own adapter. |
| `Segment::create(string $name, int\|string $adapter, array $options = []): self` | Legacy v1 factory; kept for BC. |
| `adapter(): AdapterInterface` | Escape hatch for adapter-specific methods. |
| `get/set/has/remove/collective/destroy` | Forwarded to the underlying adapter. |

### `AdapterInterface`

| Method | Purpose |
| --- | --- |
| `get(string $key, mixed $default = null): mixed` | Look up a value or fall back to `$default`. |
| `set(string $key, mixed $value): static` | Assign / replace a value. |
| `collective(array $data): static` | Atomic bulk write. Cookie adapters emit one `Set-Cookie` instead of N. |
| `has(string $key): bool` | Existence check (a stored `null` still counts as present). |
| `remove(string ...$keys): static` | Drop one or more keys (missing keys are a no-op). |
| `destroy(): bool` | Tear down the backing store. Subsequent calls raise `RuntimeException`. |

### `Permission`

| Method | Purpose |
| --- | --- |
| `is(string ...$names): bool` | True when **any** of the names is present. Case-insensitive. |
| `push(string ...$names): int` | Adds names, returns the count actually inserted. |
| `remove(string ...$names): int` | Removes names, returns the count actually removed; the list is reindexed. |
| `getPermissions(): list<string>` | Snapshot of the current permission list. |

Magic accessors: `$perm->is_admin` (call), `isset($perm->is_admin)`,
`unset($perm->is_admin)`.

## CookieAdapter options

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `salt` | `string` | — required | At least 32 bytes. Use `bin2hex(random_bytes(32))`. |
| `expires` | `int\|null` | now + 86 400 s | Unix timestamp. `null` resets to the default. |
| `path` | `string` | `'/'` | RFC 6265 path scope. |
| `domain` | `string` | `''` | Empty disables the `Domain` attribute. |
| `secure` | `bool` | `true` | When false, modern browsers reject `SameSite=None`. |
| `httponly` | `bool` | `true` | Blocks JS access via `document.cookie`. |
| `samesite` | `'Lax'\|'Strict'\|'None'` | `'Lax'` | `'None'` is rejected unless `secure=true`. |

### Cookie wire format

```
base64url(json_encode($data)) . "." . hash_hmac('sha256', $json, $salt)
```

The signature is verified with `hash_equals()` **before** the JSON is
decoded, so a forged or modified cookie never reaches the parser.

## Exceptions

| Exception | Raised when |
| --- | --- |
| `InvalidArgumentException` | Missing/short/non-string `salt`, `SameSite=None` without `Secure`, unknown adapter constant, missing adapter class, class that does not extend `AbstractAdapter`. |
| `RuntimeException` | `SessionAdapter` constructed with no active session, or any read/write on an adapter whose `destroy()` has been called. |
| `BadMethodCallException` | `Permission::__call()` invoked with a name that does not start with `is_`. |

## Development

```bash
composer install
composer test         # PHPUnit
composer analyse      # PHPStan (level 8)
composer cs:check     # PHP-CS-Fixer dry-run
composer cs:fix       # PHP-CS-Fixer apply
```

CI runs the matrix across PHP 8.0, 8.1, 8.2, 8.3, and 8.4.

## Documentation

- [docs/getting-started.md](docs/getting-started.md) — five-minute tour
- [docs/permissions.md](docs/permissions.md) — `Permission` recipes
- [docs/adapters/session.md](docs/adapters/session.md) — `SessionAdapter`
- [docs/adapters/cookie.md](docs/adapters/cookie.md) — `CookieAdapter`,
  salt generation, SameSite/Secure guidance
- [docs/adapters/custom.md](docs/adapters/custom.md) — building your own
  adapter (with a safe PDO-backed example)
- [docs/adapters/null.md](docs/adapters/null.md) — `NullAdapter` and when
  to use it
- [docs/upgrading-from-v1.md](docs/upgrading-from-v1.md) — v1 → v2
  migration notes

## Upgrading from v1

v2 ships intentional behaviour changes — most notably a new cookie
format (old cookies become unreadable and are rolled), case-folding moved
into the `Permission` constructor, a stricter cookie default profile,
`NullAdapter::has()` returning `false` instead of `true`, and a clean
adapter interface that no longer enforces a constructor signature. See
[docs/upgrading-from-v1.md](docs/upgrading-from-v1.md).

## Contributing & Security

- [Contributing guidelines](https://github.com/InitPHP/.github/blob/main/CONTRIBUTING.md)
- [Code of Conduct](https://github.com/InitPHP/.github/blob/main/CODE_OF_CONDUCT.md)
- [Security policy](https://github.com/InitPHP/.github/blob/main/SECURITY.md)

## Credits

- [Muhammet ŞAFAK](https://www.muhammetsafak.com.tr) <<info@muhammetsafak.com.tr>>

## License

Released under the [MIT License](./LICENSE).
