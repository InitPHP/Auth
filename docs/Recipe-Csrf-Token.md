# Recipe: CSRF Token Store

A CSRF token is a per-session secret that the server issues and the
client echoes back on every state-changing request. It defeats the
class of attacks where a third-party site causes the user's browser to
issue an authenticated request without their knowledge.

This recipe uses a dedicated [`Segment`](Segment.md) to hold the token,
rotate it on login, and validate it on POST/PUT/DELETE.

## Goal

For every authenticated request:

1. If no CSRF token exists in the session segment, generate one.
2. If the request is state-changing (POST/PUT/PATCH/DELETE), require
   a matching token in the request body.
3. On a successful login (or any privilege escalation), rotate the
   token so a token captured pre-login cannot be replayed.

## Working example

### Bootstrap on every request

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use InitPHP\Auth\Segment;

session_start();

$csrf = Segment::session('csrf');

if (!$csrf->has('token')) {
    $csrf->set('token', bin2hex(random_bytes(32)));
}

$csrfToken = $csrf->get('token');
```

### Embedding the token in a form

```php
?>
<form method="post" action="/comments">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <textarea name="body"></textarea>
    <button>Post</button>
</form>
<?php
```

### Validating on a state-changing request

```php
$method = $_SERVER['REQUEST_METHOD'];

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $submitted = $_POST['_csrf'] ?? '';
    $expected  = $csrf->get('token', '');

    if (!hash_equals($expected, $submitted)) {
        http_response_code(419);
        exit('CSRF validation failed.');
    }
}
```

Note the use of `hash_equals()` — `===` is a timing oracle on the
token and must be avoided.

### Rotating on login

The right time to rotate is **immediately after** a successful
authentication and any privilege escalation. A token issued before
login should not survive into the authenticated session.

```php
// inside your login handler, after password_verify() succeeds:
$auth->set('user_id', $user['id']);

session_regenerate_id(true);
$csrf->set('token', bin2hex(random_bytes(32)));
```

`session_regenerate_id(true)` is the standard defence against session
fixation. Pair it with the CSRF rotation so a pre-login fixture token
is also invalidated.

## How the pieces fit

| Step | Where | What it does |
| --- | --- | --- |
| Bootstrap | Front controller | Ensures every request has a CSRF token to render into forms. |
| Embed | Form template | Sends the token to the client in the rendered HTML. |
| Validate | Front controller (state-changing branch) | Constant-time compare between the submitted and stored tokens. |
| Rotate | Login / privilege-change handler | Replaces the token so pre-login captures cannot be replayed. |

The `csrf` segment is intentionally separate from the `auth` segment.
That way:

- A logged-out user still has CSRF protection for state-changing
  endpoints (e.g. account-creation flow).
- A logout that wipes the auth segment leaves the CSRF token in place,
  so the next form submission still validates.
- The CSRF token can be rotated independently of the auth state.

## Why not a custom HTTP header?

The pattern above uses a hidden form field, which works for any
server-rendered HTML. APIs and SPAs usually prefer a custom header
(`X-CSRF-Token`) that they read once from a `<meta>` tag and attach to
every XHR / fetch. Either pattern works — the segment store stays the
same; only the validation step changes:

```php
$submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$expected  = $csrf->get('token', '');

if (!hash_equals($expected, $submitted)) {
    http_response_code(419);
    exit('CSRF validation failed.');
}
```

## Double-submit pattern for stateless APIs

If you cannot keep a session at all (a truly stateless API), use the
[double-submit cookie pattern](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html#double-submit-cookie)
instead. Issue the CSRF token as a `Secure; SameSite=Strict` cookie
**without** the `HttpOnly` flag, and require the client to echo it
back in a header on every state-changing request. The server then
compares the cookie value against the header value with
`hash_equals()`.

You can model the cookie side of that pattern with `CookieAdapter`,
but it is usually overkill — a plain `setcookie('csrf', $token, [...])`
is enough because the server never reads or signs the value; it only
checks that the header matches the cookie.

## Operational notes

- **Token TTL.** A session-bound token expires when the session does.
  If you set short session lifetimes, generate a fresh token on every
  request rather than per session.
- **Token uniqueness per form.** Some libraries issue a fresh token
  per form, which lets you scope CSRF protection more tightly. For
  most apps the single-per-session token above is enough.
- **Header on AJAX.** Browsers do not include custom headers on
  cross-origin requests by default, which is what makes the
  `X-CSRF-Token` header pattern work.
- **Logging failures.** A CSRF validation failure on an authenticated
  endpoint is worth logging as a security event — repeated failures
  from one IP are a strong signal.

## Common mistakes

- **Comparing with `===` or `==`.** Use `hash_equals()`. Anything
  else is a timing oracle.
- **Skipping the rotation on login.** Without rotation, a CSRF token
  captured pre-login lets an attacker forge requests once the user
  authenticates.
- **Storing the token in a cookie that the server reads.** That is
  not CSRF protection — the attacker's malicious request would also
  carry the cookie. Either keep the token in the session (this
  recipe) or use the double-submit pattern where the *header* is
  what proves the request originated from your site.
- **Reusing the same token across browser tabs without rotation.**
  Tab A signs in, rotates the token, and tab B is suddenly invalid
  for the next POST. Accept this and refresh the token at the top
  of every authenticated page render; or use per-form tokens.

## Where to go next

- [Recipe → Multi-Segment](Recipe-Multi-Segment.md) — auth + cart +
  csrf together.
- [Security](Security.md) — broader operational concerns.
- [Session Adapter](Session-Adapter.md) — the backing store this recipe
  uses.
