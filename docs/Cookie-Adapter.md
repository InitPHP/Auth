# Cookie Adapter

`InitPHP\Auth\CookieAdapter` stores auth state inside a signed,
JSON-encoded cookie. It is the backing store for
[`Segment::cookie()`](Segment.md#factory-methods).

## When to pick it

- You want a stateless server — no `session_start()`, no save-handler
  storage, no session locking.
- You want auth state to survive a server restart or move with the
  user across deployments.
- You can fit the payload inside ~4 KB (the practical cookie size
  limit; most browsers cap at 4096 bytes per cookie).

If you need more capacity, server-side guarantees, or to store secrets,
prefer [`SessionAdapter`](Session-Adapter.md) or a
[custom database adapter](Custom-Adapters.md).

> **The cookie is signed, not encrypted.** Treat the payload as
> publicly readable. Put a user id and a role; do not put a password
> hash or an API key.

## Basic usage

```php
use InitPHP\Auth\Segment;

$auth = Segment::cookie('auth', [
    'salt'   => $_ENV['AUTH_COOKIE_SECRET'], // 32+ bytes, env-loaded
    'path'   => '/',
    'domain' => 'example.com',
]);

$auth->set('user_id', 42)->set('role', 'editor');

// On the next request, after the cookie round-trips
$auth->get('user_id');   // 42
$auth->has('role');      // true
```

## Wire format

The cookie value looks like this:

```
base64url(json_encode($data)) . "." . hash_hmac('sha256', $json, $salt)
```

Three things matter:

1. **JSON, not `serialize()`.** The decoder runs `json_decode()`, which
   cannot instantiate objects, so there is no POP-gadget path even if
   the rest of the verification chain were broken.
2. **HMAC-SHA256.** Hand-rolled hash schemes (`md5(sha1(...))` etc.)
   are fragile under length-extension and timing attacks. HMAC-SHA256
   is the boring, correct choice.
3. **The signature is verified `BEFORE` the JSON is decoded.** A
   tampered cookie never reaches the parser. The comparison uses
   `hash_equals()`, so the verification step is constant-time and does
   not leak the secret under a timing oracle.

```php
// Roughly what the decoder does, condensed
[$encodedPayload, $signature] = explode('.', $_COOKIE['auth'], 2);
$payload                       = base64UrlDecode($encodedPayload);

if (!hash_equals(hash_hmac('sha256', $payload, $salt), $signature)) {
    return [];   // tampered or wrong salt — silently drop
}

$data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
```

Any failure — missing cookie, malformed format, bad signature, invalid
JSON, non-array root — yields an empty bag. The user is issued a fresh
cookie on their next write; there is no decode path that would risk
running `unserialize()` against attacker-controlled bytes.

## Generating a salt

The salt is the HMAC key. Treat it as you would any other secret.

```php
// Run once, store the result in your environment (.env, secrets manager).
echo bin2hex(random_bytes(32)), PHP_EOL;
// e.g. "9f6c1a7d3e8b50…"  — 64 hex characters, 32 bytes of entropy
```

The constructor rejects anything shorter than 32 bytes:

```php
Segment::cookie('auth', ['salt' => 'too-short']);
// InvalidArgumentException: A "salt" of at least 32 bytes must be supplied.
//   Generate one with bin2hex(random_bytes(32)).
```

Rotating the salt invalidates every existing cookie. Plan for a logout
of all users when you rotate. See [Security → Salt
rotation](Security.md#salt-rotation) for the operational playbook.

## Constructor options

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `salt` | `string` | **required** | At least 32 bytes. Shorter values throw `InvalidArgumentException`. |
| `expires` | `int\|null` | `time() + 86400` | Unix timestamp. `null` resets to the default. |
| `path` | `string` | `'/'` | RFC 6265 path scope. |
| `domain` | `string` | `''` | Empty disables the `Domain` attribute. |
| `secure` | `bool` | `true` | When false, modern browsers reject `SameSite=None`. |
| `httponly` | `bool` | `true` | Blocks JS access via `document.cookie`. |
| `samesite` | `'Lax'\|'Strict'\|'None'` | `'Lax'` | `'None'` requires `secure=true`. |

The defaults are deliberately strict. The pre-flight check rejects the
unsafe combination eagerly:

```php
Segment::cookie('auth', [
    'salt'     => $secret,
    'samesite' => 'None',
    'secure'   => false,
]);
// InvalidArgumentException: SameSite=None requires the cookie to be marked Secure.
```

## Lifecycle

| Call | Effect |
| --- | --- |
| `get($k, $default)` | Reads from the in-memory snapshot decoded at construction time. |
| `set($k, $v)` | Updates the snapshot, re-encodes, emits one `Set-Cookie` header via the [writer](Cookie-Writer.md). |
| `collective([...])` | Same, but for the whole payload in one go — one `Set-Cookie` instead of N. |
| `has($k)` | Reads from the snapshot. |
| `remove(...$k)` | Drops keys, re-encodes, emits one `Set-Cookie`. |
| `destroy()` | Emits a deletion cookie that matches the original `path`/`domain` and unsets `$_COOKIE[$name]`. Returns the writer's success flag. |

After `destroy()`, any read or write on the adapter raises
`\RuntimeException`.

## Why `destroy()` reuses the original options

RFC 6265 deletes cookies by emitting a fresh `Set-Cookie` with the
**same** `path`, `domain`, `secure`, and `samesite` attributes — and an
`expires` in the past. If you pass different attributes, the browser
keeps the original cookie alive.

The adapter handles this for you: `destroy()` clones the original
`$this->options` array and only overrides `expires`:

```php
$auth = Segment::cookie('auth', [
    'salt' => $secret,
    'path' => '/admin',
]);

$auth->destroy();
// Set-Cookie: auth=; expires=Thu, 01 Jan 1970 00:00:00 GMT;
//             path=/admin; domain=; secure; httponly; samesite=Lax
```

> **v1 bug, fixed.** v1's `destroy()` only set `expires` and left
> `path`/`domain` empty, so cookies originally written with a custom
> path refused to delete. v2 fixes this — no action required on your
> side.

## Reading existing data

The constructor reads `$_COOKIE[$name]` and tries to decode it. Any
failure yields an empty bag — the user is issued a fresh cookie on
their next write:

| Failure mode | Behaviour |
| --- | --- |
| Cookie missing | Empty bag. |
| Cookie is not a string (`$_COOKIE` was polluted) | Empty bag. |
| No `.` separator (legacy v1 cookie, garbage input) | Empty bag. |
| Bad base64url payload | Empty bag. |
| Signature does not verify | Empty bag — `hash_equals()` returns false. |
| Wrong salt (someone else's cookie, rotated salt) | Empty bag. |
| `JSON_THROW_ON_ERROR` raises | Empty bag. |
| JSON decoded to a non-array | Empty bag. |

The implementation looks roughly like this:

```php
private function decoder(): array
{
    if (!isset($_COOKIE[$this->name]) || !\is_string($_COOKIE[$this->name])) {
        return [];
    }
    $raw = $_COOKIE[$this->name];
    if (\strpos($raw, '.') === false) {
        return [];
    }
    [$encodedPayload, $signature] = \explode('.', $raw, 2);

    $payload = $this->base64UrlDecode($encodedPayload);
    if ($payload === null) {
        return [];
    }
    if (!\hash_equals($this->generateSignature($payload), $signature)) {
        return [];
    }

    try {
        $data = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return [];
    }

    return \is_array($data) ? $data : [];
}
```

## Injecting a custom writer

The constructor accepts an optional third argument: a
[`CookieWriterInterface`](Cookie-Writer.md). The default is
`NativeCookieWriter` (delegates to PHP's `setcookie()`); tests inject
`InMemoryCookieWriter` to capture every call without touching response
headers.

```php
use InitPHP\Auth\Cookie\InMemoryCookieWriter;
use InitPHP\Auth\CookieAdapter;

$writer  = new InMemoryCookieWriter();
$adapter = new CookieAdapter('auth', ['salt' => $secret], $writer);

$adapter->set('user_id', 42);

$writer->lastCall();
// ['name' => 'auth', 'value' => 'eyJ1c2VyX2lkIjo0Mn0.abc…', 'options' => [...]]
```

See [Cookie Writer](Cookie-Writer.md) for the interface and the two shipped
implementations.

## Legacy v1 cookies

v1 cookies were `base64(serialize([data, hash]))` — no dot separator,
no HMAC, decoded by `unserialize()`. v2's decoder hits the "no `.`
separator" guard and returns an empty bag, so users holding a v1
cookie are silently logged out and re-issued a v2 cookie on next write.

There is no migration path that would risk running `unserialize()` on
attacker bytes. See [Migration Guide → Cookie wire
format](Migration-Guide.md#cookieadapter--new-wire-format) for the
rationale.

## Common mistakes

- **Hard-coding the salt in source.** It belongs in your secrets
  manager / environment, not in a committed file. A leaked salt
  invalidates the entire signature scheme.
- **Reusing one salt across applications.** Use a different secret per
  application so a leak does not cascade.
- **Storing secrets in the cookie.** Sign, do not encrypt. The cookie
  is readable by anyone who intercepts it — keep it limited to ids
  and tokens.
- **Setting `expires` to a relative number.** The option is a Unix
  timestamp, not a TTL. Use `time() + 3600`, not `3600`.
- **Calling `setcookie('auth', '')` by hand to log out.** Use
  `$auth->destroy()` — it reuses the original `path` and `domain` so
  the browser actually deletes the cookie.

## Where to go next

- [Cookie Writer](Cookie-Writer.md) — the abstraction used internally for
  testability.
- [Security](Security.md) — threat model, salt rotation playbook,
  encryption note.
- [Testing](Testing.md) — InMemoryCookieWriter patterns.
- [Recipes → Remember-Me](Recipe-Remember-Me.md) — long-lived cookie
  pattern.
- [API Reference](API-Reference.md#cookieadapter) — full method
  signatures.
