# Multiple segments per request

A `Segment` is just a named slice of state. Nothing stops you from
having several side by side — one for auth, one for the shopping cart,
one for the CSRF token — each backed by whatever store fits it best.

## Goal

A typical e-commerce request needs three independent pieces of state:

- **Auth** — durable, lives across browser sessions. Cookie.
- **Cart** — page-scoped, lives only as long as the browser tab. Session.
- **CSRF** — per-request token, rotated on login. Session.

Each segment has its own name; they cannot collide because the
underlying adapters key into different slots.

## Working example

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use InitPHP\Auth\Permission;
use InitPHP\Auth\Segment;

session_start();

$auth = Segment::cookie('auth', [
    'salt'   => $_ENV['AUTH_COOKIE_SECRET'],
    'path'   => '/',
    'domain' => 'shop.example.com',
]);

$cart = Segment::session('cart');
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
$csrf->set('token', bin2hex(random_bytes(16)));
```

After this script runs, the request response carries:

- a `Set-Cookie: auth=…` header signed with your secret;
- the cart and CSRF token in `$_SESSION['cart']` and `$_SESSION['csrf']`.

## Logout

Tear down only what belongs to that flow. The auth cookie goes, the
cart can survive, the CSRF token rotates:

```php
$auth->destroy();
$csrf->set('token', bin2hex(random_bytes(16)));
// $cart is untouched
```

`SessionAdapter::destroy()` only `unset()`s its own slot — it never
calls `session_destroy()`, so a logout that uses `$auth->destroy()` on
a session-backed auth segment will not wipe `cart` or `csrf`. That is
the point of segmenting state.

## Common mistakes

- **Reusing one segment name across storage backends.** The name lives
  inside the store, not across them. Calling `Segment::session('auth')`
  and `Segment::cookie('auth')` in the same request gives you two
  unrelated stores that happen to share a label.
- **Calling `session_destroy()` in a logout.** That nukes every
  segment plus any unrelated session state. Use the specific
  `$segment->destroy()` calls for the segments you actually want to
  clear.
- **Storing the entire `Permission` object in the auth cookie.**
  Cookies are signed but not encrypted; serialised PHP objects are
  also a much larger payload than a flat array. Store the list:
  `$auth->set('permissions', $perm->getPermissions())`.
