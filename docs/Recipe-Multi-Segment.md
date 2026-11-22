# Recipe: Multi-Segment Request

A `Segment` is a named slice of state. Nothing stops you from running
several side by side — one for auth, one for the cart, one for the
CSRF token — each backed by whatever store fits it best.

## Goal

A typical e-commerce request needs three independent pieces of state:

- **Auth** — durable, lives across browser sessions. Cookie.
- **Cart** — page-scoped, lives only as long as the browser tab.
  Session.
- **CSRF** — per-request token, rotated on login. Session.

Each segment has its own name; they cannot collide because the
underlying adapters key into different slots.

## Working example

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use InitPHP\Auth\Permission;
use InitPHP\Auth\Segment;

session_start();

// 1. Auth — cookie-backed, durable across sessions.
$auth = Segment::cookie('auth', [
    'salt'   => $_ENV['AUTH_COOKIE_SECRET'],
    'path'   => '/',
    'domain' => 'shop.example.com',
]);

// 2. Cart — session-backed, lives with the browser tab.
$cart = Segment::session('cart');

// 3. CSRF — session-backed, rotated on login.
$csrf = Segment::session('csrf');

// Hydrate the user from the auth cookie.
$userId = $auth->get('user_id');
if ($userId !== null) {
    $perm = new Permission($auth->get('permissions', []));
} else {
    $perm = new Permission();
}

// Mutate the cart.
$cart->set('items', [
    ['sku' => 'ABC-123', 'qty' => 2],
    ['sku' => 'XYZ-007', 'qty' => 1],
]);

// Rotate the CSRF token at the start of every authenticated request.
if ($userId !== null) {
    $csrf->set('token', bin2hex(random_bytes(16)));
}
```

After this script runs, the request response carries:

- A `Set-Cookie: auth=…` header signed with your secret;
- The cart and CSRF token in `$_SESSION['cart']` and `$_SESSION['csrf']`;
- The page-rendering code can call `$perm->is('admin')` for role gates.

## How the pieces fit

| Segment | Backing store | Lifetime | Reset trigger |
| --- | --- | --- | --- |
| `auth` | Signed cookie | 24 hours (default) | Manual logout or cookie expiry. |
| `cart` | `$_SESSION['cart']` | Browser session | `session_destroy()` or `$cart->destroy()`. |
| `csrf` | `$_SESSION['csrf']` | Rotated on every login | `$csrf->set('token', ...)` overrides. |

Three observations worth stating explicitly:

1. **The names live inside the store, not across them.** Calling
   `Segment::session('auth')` and `Segment::cookie('auth')` in the
   same request gives you two unrelated stores that happen to share
   a label. Choose names per logical purpose, not per storage type.
2. **`SessionAdapter::destroy()` only `unset()`s its own slot.** It
   does not call `session_destroy()`, so wiping `$cart` does not
   wipe `$csrf`, and vice versa. That is the point of segmenting.
3. **The cookie segment is independent of the session.** A user who
   loses their browser session still has their auth state — the
   cookie travels with the next request.

## Logout

Tear down only what belongs to that flow. The auth cookie goes, the
cart can survive, the CSRF token rotates:

```php
$auth->destroy();
$csrf->set('token', bin2hex(random_bytes(16)));
// $cart is untouched
```

This is the right shape for "log out, keep the cart". If you want a
full reset, also call `$cart->destroy()` and `session_regenerate_id(true)`.

## Operational notes

- **Cookie size cap.** Each cookie is limited to ~4 KB. If the auth
  segment grows beyond that (large permission list, long user
  preferences), move the heavy bits into a server-side store and keep
  only an id in the cookie.
- **CSRF token storage.** Storing the CSRF token in a session segment
  is fine for synchronous, server-rendered apps. SPAs and APIs
  should use the
  [double-submit cookie pattern](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html#double-submit-cookie)
  instead — see [Recipe → CSRF Token](Recipe-Csrf-Token.md).
- **Cart lifetime.** Carts living in `$_SESSION` evaporate when the
  session expires. For "guest cart that survives" patterns, mirror
  the cart to a database keyed by a long-lived anonymous cookie.

## Common mistakes

- **Reusing one segment name across storage backends.** As above, the
  name only matters inside its own store; you will not get the
  cross-storage behaviour you might expect.
- **Calling `session_destroy()` in a logout.** That nukes every
  segment plus any unrelated session state. Use the specific
  `$segment->destroy()` calls for the segments you actually want to
  clear.
- **Storing the entire `Permission` object in the auth cookie.**
  Cookies are signed but not encrypted; serialised PHP objects are
  also a much larger payload than a flat array. Store the list:
  `$auth->set('permissions', $perm->getPermissions())`.

## Where to go next

- [Recipe → CSRF Token](Recipe-Csrf-Token.md) — the same `$csrf` segment
  pattern, but with the validation step on the next request.
- [Recipe → Remember-Me](Recipe-Remember-Me.md) — a longer-lived
  cookie alongside the regular auth segment.
- [Security](Security.md) — operational concerns around the cookie
  segment.
