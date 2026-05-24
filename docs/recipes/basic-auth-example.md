# HTTP Basic-auth + database lookup

This recipe ports the `BasicAuthAdapter` example from the v1 README into
something you would actually deploy: prepared statements, hashed
passwords, and a clean separation between authentication
(who are you?) and authorization (what may you do?).

The adapter class itself is documented in
[Custom adapters](../adapters/custom.md#a-safer-pdo-backed-example) —
this page shows the full request lifecycle around it.

## Goal

A protected admin endpoint that:

1. Requires HTTP Basic credentials.
2. Looks the user up in a `users` table.
3. Verifies the password against `password_hash()` output.
4. Exposes the matched row as an auth segment.
5. Builds a `Permission` set from the user's role.
6. Gates the endpoint on `is('admin')`.

## Schema

```sql
CREATE TABLE users (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin', 'editor', 'viewer') NOT NULL DEFAULT 'viewer'
);
```

Generate password hashes when you create the user:

```php
$pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
    ->execute(['alice', password_hash('s3cret', PASSWORD_DEFAULT), 'admin']);
```

## Working example

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Auth\BasicAuthAdapter;
use InitPHP\Auth\Permission;
use InitPHP\Auth\Segment;

$auth = Segment::custom('auth', BasicAuthAdapter::class, [
    'dsn'      => 'mysql:host=localhost;dbname=app;charset=utf8mb4',
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
]);

// At this point either the request authenticated successfully or the
// adapter has already sent a 401 and exit()ed.

$perm = new Permission([$auth->get('role')]);

if (!$perm->is('admin')) {
    http_response_code(403);
    exit('Admins only.');
}

printf("Welcome, %s.\n", $auth->get('username'));
```

## How the pieces fit

- **`BasicAuthAdapter`** owns the authentication step. Its constructor
  reads `$_SERVER['PHP_AUTH_USER']` / `$_SERVER['PHP_AUTH_PW']`, looks
  the user up, and either populates the in-memory row or sends a `401`
  and exits.
- **`Segment::custom()`** wires the adapter into the standard
  `AdapterInterface` so that the rest of your code does not have to
  know it talks to a database. You can swap the adapter for a Redis
  one later without touching the call sites.
- **`Permission`** translates the database role into a permission set.
  Case-insensitivity means the database can store `'Admin'` and the
  call site still asks for `'admin'`.

## Common mistakes

- **Calling `md5()` on the password.** Use `password_verify()` against
  the stored `password_hash()` output. The v1 README example used
  `md5(...)`, which is unsuitable for password storage.
- **Concatenating the username into SQL.** Bind it. The
  [adapter source](../adapters/custom.md#a-safer-pdo-backed-example)
  shows how — never write `... WHERE username = '" . $username . "'"`.
- **Re-authenticating on every request without caching.** A real
  deployment would put the matched user id in a session or signed
  cookie after the first Basic auth, so subsequent requests skip the
  database round-trip. Use `Segment::cookie()` or `Segment::session()`
  for that hop, with `BasicAuthAdapter` only as the initial credential
  check.
