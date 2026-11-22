# InitPHP Auth — Wiki

Welcome to the official documentation for **`initphp/auth`** — a small
PHP 8 authentication & authorization library with pluggable storage
adapters (session, signed cookie, custom) and a tiny case-insensitive
permission set.

The package ships these public types:

| Type | Purpose |
| --- | --- |
| [`Segment`](Segment.md) | Facade in front of a single adapter. Pick the adapter via factory methods. |
| [`AdapterInterface`](Adapter-Interface.md) | Storage contract — depend on this in your services. |
| [`AbstractAdapter`](Adapter-Interface.md#abstractadapter) | Base class with a default `collective()`. Most custom adapters extend this. |
| [`SessionAdapter`](Session-Adapter.md) | `$_SESSION`-backed storage. |
| [`CookieAdapter`](Cookie-Adapter.md) | Signed-cookie storage (JSON + HMAC-SHA256). |
| [`NullAdapter`](Null-Adapter.md) | Null Object — accepts every operation, stores nothing. |
| [`CookieWriterInterface`](Cookie-Writer.md) | Abstraction over `setcookie()` so the cookie adapter is testable. |
| [`Permission`](Permissions.md) | Case-insensitive named permission set. |

```bash
composer require initphp/auth
```

```php
use InitPHP\Auth\Permission;
use InitPHP\Auth\Segment;

session_start();

$auth = Segment::session('auth');
$auth->set('user_id', 42)->set('role', 'editor');

$perm = new Permission([$auth->get('role')]);
if ($perm->is('editor')) {
    // do editor things
}
```

## Start here

- **New to the package?** Read [Installation](Installation.md), then
  [Quick Start](Quick-Start.md).
- **Building a permission system?** Read [Permissions](Permissions.md).
- **Choosing an adapter?** See the adapter comparison in
  [Quick Start → Picking an adapter](Quick-Start.md#picking-an-adapter)
  and the per-adapter pages:
  [Session](Session-Adapter.md) ·
  [Cookie](Cookie-Adapter.md) ·
  [Null](Null-Adapter.md) ·
  [Custom](Custom-Adapters.md).
- **Auditing cookie security?** Read [Security](Security.md) and
  [Cookie Adapter → Wire format](Cookie-Adapter.md#wire-format).
- **Writing tests against the auth layer?** Read [Testing](Testing.md).
- **Upgrading from v1?** Read the [Migration Guide](Migration-Guide.md).
- **Looking for a specific method?** The full
  [API Reference](API-Reference.md) lists every class member.

## At a glance — adapter feature matrix

| Capability | `SessionAdapter` | `CookieAdapter` | `NullAdapter` | Custom |
| --- | :---: | :---: | :---: | :---: |
| Per-request lifetime | ✅ (until session ends) | ✅ (until expiry) | n/a | ✅ |
| Survives PHP restart | ⚠️ (if session save handler persists) | ✅ | n/a | depends |
| Stateless server | ❌ | ✅ | ✅ | depends |
| Tamper-proof | ✅ (server-side) | ✅ (HMAC-SHA256) | n/a | depends |
| Capacity | bounded by `$_SESSION` | bounded by cookie size (~4 KB) | unlimited (no-op) | depends |
| Testable without headers | n/a | ✅ ([`CookieWriterInterface`](Cookie-Writer.md)) | ✅ | depends |
| Atomic bulk write (`collective()`) | ✅ (one `$_SESSION` write) | ✅ (one `Set-Cookie`) | n/a | implements default |

## Package metadata

- **License**: MIT
- **Minimum PHP**: 8.2 (tested through 8.5)
- **Runtime dependencies**: [`initphp/parameterbag`](https://github.com/InitPHP/ParameterBag) `^3.0`
- **PHP extensions**: `ext-json`, `ext-hash` (bundled with default PHP builds)
- **Packagist**: [`initphp/auth`](https://packagist.org/packages/initphp/auth)
- **Source**: [github.com/InitPHP/Auth](https://github.com/InitPHP/Auth)
- **Issues**: [github.com/InitPHP/Auth/issues](https://github.com/InitPHP/Auth/issues)
- **Discussions**: [github.com/orgs/InitPHP/discussions](https://github.com/orgs/InitPHP/discussions)
- **Security**: [`SECURITY.md`](https://github.com/InitPHP/.github/blob/main/SECURITY.md)

If something in this wiki is unclear, ambiguous, or wrong, please
[open an issue](https://github.com/InitPHP/Auth/issues/new) —
documentation fixes are reviewed eagerly.
