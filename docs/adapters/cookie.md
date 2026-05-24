# CookieAdapter

`InitPHP\Auth\CookieAdapter` stores auth state in a signed, JSON-encoded
cookie. It is the backing store for `Segment::cookie()`.

## How the cookie is built

```
base64url(json_encode($data)) . "." . hash_hmac('sha256', $json, $salt)
```

The signature is verified with `hash_equals()` **before** the JSON is
decoded, so a forged or tampered cookie never reaches the parser. There
is no encryption — the payload is plain JSON. **Do not put secrets in
it.** Put a session id, a user id, a CSRF token. Anything you would not
write into a server log probably does not belong here.

## Goal

Keep the logged-in user's id and role on the client without holding a
server-side session. The cookie travels with every request, is signed
with a secret only the server knows, and is rejected on any
modification.

## Working example

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use InitPHP\Auth\Segment;

$auth = Segment::cookie('auth', [
    'salt'   => $_ENV['AUTH_COOKIE_SECRET'], // 32+ bytes, loaded from env
    'path'   => '/',
    'domain' => 'example.com',
]);

$auth->set('user_id', 42)->set('role', 'editor');

echo $auth->get('user_id'), PHP_EOL;  // 42
```

Expected output (first response writes the cookie; subsequent requests
read it back):

```
42
```

## Generating a salt

The salt is the HMAC key. Treat it as you would any other secret.

```php
// Run once, store the result in your environment (.env, secrets manager).
echo bin2hex(random_bytes(32)), PHP_EOL;
// e.g. "9f6c1a7d3e8b50…"  — 64 hex characters, 32 bytes of entropy
```

Rotating the salt invalidates every existing cookie. Plan for a logout
of all users when you rotate.

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

## Cleaning up: `destroy()`

Deleting a cookie is not just "set an empty value". The browser only
honours the deletion when the headers carry the **same** `path` and
`domain` as the original. The adapter reuses `$this->options` and only
overrides `expires`, so the cookie set with `path=/admin` is removed
with `path=/admin`.

```php
$auth = Segment::cookie('auth', [
    'salt' => $secret,
    'path' => '/admin',
]);

$auth->destroy();
// Set-Cookie: auth=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/admin; ...
```

After `destroy()`, any read or write on the adapter raises
`RuntimeException`.

## Testing without touching response headers

`CookieAdapter` accepts a third constructor argument: a
`CookieWriterInterface`. The default is `NativeCookieWriter` (delegates
to `setcookie()`); a test can swap in `InMemoryCookieWriter`, which
records every call instead of emitting a header.

```php
use InitPHP\Auth\Cookie\InMemoryCookieWriter;
use InitPHP\Auth\CookieAdapter;

$writer = new InMemoryCookieWriter();
$adapter = new CookieAdapter('auth', ['salt' => $secret], $writer);

$adapter->set('user_id', 42);

$last = $writer->lastCall();
// $last === ['name' => 'auth', 'value' => 'eyJ1c2VyX2lkIjo0Mn0.abc…', 'options' => [...]]
```

Use this in unit tests to assert that `destroy()` actually emits the
matching path/domain, or that `collective()` only fires one
`Set-Cookie` for a bulk write.

You can also implement your own writer — for example to route cookies
through a PSR-7 response object instead of PHP's `setcookie()`.

## Legacy v1 cookies

v1 cookies were `base64(serialize([...]))`. v2 cannot read them: they
have no dot separator, so the decoder treats them as malformed and
returns an empty bag. Users will be issued fresh v2 cookies on their
next write — there is no decode path that would risk running an
`unserialize()` against attacker-controlled bytes.

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
