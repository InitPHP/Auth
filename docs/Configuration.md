# Configuration

All adapter-specific configuration is passed through the `$options`
array of the adapter constructor (or, equivalently, the third argument
of the [`Segment`](Segment.md) factories). This page is the
single-source-of-truth for every option the package accepts.

## SessionAdapter

`new SessionAdapter(string $name, array $options = [])`

The `$options` array is forwarded straight to the internal
[`ParameterBag`](https://github.com/InitPHP/ParameterBag/wiki/Configuration).
Empty options get a `['isMulti' => false]` default; explicit options
override the default.

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `isMulti` | `bool` | `false` | Enables dotted-path access (`$auth->get('user.profile.name')`). |
| `separator` | `string` | `'.'` | Path delimiter when `isMulti` is on. |
| `caseInsensitive` | `bool` | `false` | Folds every key to lower-case on storage and lookup. |

> Unknown keys are silently ignored by `SessionAdapter`. They are
> rejected only when ParameterBag also rejects them — which it does
> for **its own** unknown keys via `ParameterBagInvalidArgumentException`.

```php
$_SESSION['auth'] = ['db' => ['host' => 'localhost', 'port' => 3306]];

$auth = new SessionAdapter('auth', ['isMulti' => true]);
$auth->get('db.host');   // 'localhost'
```

See [Session Adapter](Session-Adapter.md) for the full lifecycle.

## CookieAdapter

`new CookieAdapter(string $name, array $options, ?CookieWriterInterface $writer = null)`

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| [`salt`](#salt) | `string` | **required** | At least 32 bytes. Shorter values throw `InvalidArgumentException`. |
| [`expires`](#expires) | `int\|null` | `time() + 86400` | Unix timestamp. `null` resets to the default. |
| [`path`](#path) | `string` | `'/'` | RFC 6265 path scope. |
| [`domain`](#domain) | `string` | `''` | Empty disables the `Domain` attribute. |
| [`secure`](#secure) | `bool` | `true` | When false, modern browsers reject `SameSite=None`. |
| [`httponly`](#httponly) | `bool` | `true` | Blocks JS access via `document.cookie`. |
| [`samesite`](#samesite) | `'Lax'\|'Strict'\|'None'` | `'Lax'` | `'None'` requires `secure=true`. |

The defaults are deliberately strict. A missing or short `salt`, and
the unsafe `SameSite=None + Secure=false` combination, raise
`InvalidArgumentException` before any I/O.

### `salt`

The HMAC-SHA256 secret. Must be a string of at least 32 bytes.
Generate with:

```php
echo bin2hex(random_bytes(32)), PHP_EOL;
// e.g. "9f6c1a7d3e8b50…"  — 64 hex characters, 32 bytes of entropy
```

Load it from your environment, secrets manager, or config file — never
from a committed source file. See [Security → Salt
rotation](Security.md#salt-rotation) for the operational playbook.

### `expires`

Unix timestamp at which the cookie should expire. Default is
`time() + 86400` (24 hours). Set `null` to reset to the default.

```php
// Long-lived "remember me" cookie
Segment::cookie('remember', [
    'salt'    => $secret,
    'expires' => time() + 60 * 60 * 24 * 30,   // 30 days
]);
```

> The option is a **Unix timestamp**, not a TTL. `time() + 3600`, not
> `3600`. See [Recipe → Remember-Me](Recipe-Remember-Me.md).

### `path`

The path prefix the cookie is sent for. Default `'/'`.

The path is reused by `destroy()` — see [Cookie Adapter → Why destroy()
reuses the original options](Cookie-Adapter.md#why-destroy-reuses-the-original-options).

### `domain`

The domain the cookie is sent for. Default `''` (the domain of the
current request, without subdomains). Use `.example.com` to share the
cookie across subdomains.

### `secure`

When `true` (the default), the cookie is only sent over HTTPS.

Setting `secure=false` in production is a downgrade and disables the
`SameSite=None` path entirely. Keep it on outside of local dev.

### `httponly`

When `true` (the default), the cookie is invisible to `document.cookie`.
This is the standard defence against XSS-driven session theft.

### `samesite`

Controls cross-site cookie behaviour. Defaults to `'Lax'`.

| Value | Sent on | Use case |
| --- | --- | --- |
| `'Strict'` | Same-site requests only | Hardest setting; breaks third-party links into authenticated pages. |
| `'Lax'` | Same-site requests + top-level navigations | The default; appropriate for most web apps. |
| `'None'` | Always | Required for cross-site embedding (iframes, third-party widgets). **Requires `secure=true`.** |

The constructor rejects `SameSite=None + Secure=false` eagerly:

```php
Segment::cookie('auth', [
    'salt'     => $secret,
    'samesite' => 'None',
    'secure'   => false,
]);
// InvalidArgumentException: SameSite=None requires the cookie to be marked Secure.
```

## NullAdapter

`new NullAdapter(string $name = '', array $options = [])`

Both arguments are accepted and silently ignored. There are no options
that change behaviour.

## Custom adapters

You define the option schema. The convention is:

```php
public function __construct(string $name, array $options = [])
{
    // ... validate $options, surface bad config as InvalidArgumentException ...
}
```

See [Custom Adapters](Custom-Adapters.md) for the worked example.

## Permission

The `Permission` class has no `$options` array. The constructor
accepts a list of permission strings; everything else is implicit:

```php
new Permission(['admin', 'editor']);
```

See [Permissions](Permissions.md) for the normalization rules.

## Where to go next

- [Cookie Adapter](Cookie-Adapter.md) — full discussion of every cookie
  option.
- [Exceptions](Exceptions.md) — what each invalid option throws.
- [Security](Security.md) — operational concerns around the cookie
  options.
