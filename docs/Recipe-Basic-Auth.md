# Recipe: HTTP Basic Auth

This recipe ports the `BasicAuthAdapter` example from the v1 README
into something you would actually deploy: prepared statements, hashed
passwords, and a clean separation between authentication
(who are you?) and authorization (what may you do?).

The adapter class itself is documented in [Custom
Adapters](Custom-Adapters.md#a-safer-pdo-backed-example) — this recipe
shows the full request lifecycle around it.

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
    ->execute([
        'alice',
        password_hash('s3cret', PASSWORD_DEFAULT),
        'admin',
    ]);
```

> **Never** insert the plaintext password. **Never** use `md5()`
> or `sha1()` for password storage. `password_hash()` is the only
> correct choice; its cost parameter automatically tracks PHP
> defaults.

## The adapter

Reproduced here in condensed form — the full annotated class lives in
[Custom Adapters](Custom-Adapters.md#a-safer-pdo-backed-example):

```php
namespace App\Auth;

use InitPHP\Auth\AbstractAdapter;
use InitPHP\Auth\AdapterInterface;
use PDO;

final class BasicAuthAdapter extends AbstractAdapter
{
    private const ALLOWED_COLUMNS = ['role'];

    private PDO $pdo;
    private array $user;

    public function __construct(string $name, array $options)
    {
        $this->pdo = new PDO($options['dsn'], $options['username'], $options['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->user = $this->authenticate();
    }

    private function authenticate(): array
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? '';
        $password = $_SERVER['PHP_AUTH_PW']   ?? '';

        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, $row['password_hash'])) {
            header('WWW-Authenticate: Basic realm="Private Area"');
            header('HTTP/1.1 401 Unauthorized');
            exit('Authentication required.');
        }
        return $row;
    }

    // get/set/has/remove/destroy — see the Custom Adapters page
}
```

## The endpoint

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

- **`BasicAuthAdapter`** owns the *authentication* step. Its
  constructor reads `$_SERVER['PHP_AUTH_USER']` / `$_SERVER['PHP_AUTH_PW']`,
  looks the user up, and either populates the in-memory row or sends
  a `401` and exits.
- **`Segment::custom()`** wires the adapter into the standard
  `AdapterInterface` so that the rest of your code does not have to
  know it talks to a database. You can swap the adapter for a Redis
  one later without touching the call sites.
- **`Permission`** translates the database role into a permission set.
  Case-insensitivity means the database can store `'Admin'` and the
  call site still asks for `'admin'`.

## Operational notes

- **Re-authentication overhead.** This recipe authenticates against
  the database on **every request**. That is fine for low-traffic
  admin tools; for anything user-facing, cache the matched user id
  in a session or signed cookie after the first Basic auth so
  subsequent requests skip the database round-trip:

  ```php
  if ($cached = (new SessionAdapter('basic_auth_cache'))->get('user_id')) {
      // skip the DB call, hydrate from cache
  }
  ```

- **TLS is mandatory.** HTTP Basic sends credentials with every
  request, base64-encoded. Without TLS, every proxy and Wi-Fi
  bystander sees the password. Refuse to start the script if
  `$_SERVER['HTTPS']` is empty (or behind a `Forwarded`-aware
  reverse proxy, check the appropriate header).
- **`PDO::ATTR_EMULATE_PREPARES = false`** is non-negotiable. With
  emulated prepares, PDO concatenates the SQL itself and falls back
  to escaping rules that have known edge cases.
- **`exit('Authentication required.')`** is sloppy in production —
  return a structured 401 page instead, so users see something
  useful when they fat-finger the password.

## Common mistakes

- **Calling `md5()` on the password.** Use `password_verify()`
  against the stored `password_hash()` output. The v1 README example
  used `md5(...)`, which is unsuitable for password storage and
  appears here only to document what was wrong.
- **Concatenating the username into SQL.** Bind it. The adapter
  source shows how — never write
  `'... WHERE username = "' . $username . '"'`.
- **Catching the 401 in a try/catch.** The adapter `exit()`s on
  failure. If you want different behaviour (a JSON 401 response, a
  retry counter), pull the `authenticate()` logic out of the
  constructor and let the caller handle the failure.

## Where to go next

- [Custom Adapters](Custom-Adapters.md) — full annotated source for
  `BasicAuthAdapter` and other custom-adapter patterns.
- [Recipe → Multi-Segment](Recipe-Multi-Segment.md) — combining this
  recipe's auth segment with cart and CSRF segments.
- [Security](Security.md) — TLS, salt rotation, observability.
