# Custom adapters

Anything that implements `InitPHP\Auth\AdapterInterface` can sit behind
a `Segment`. Extending `InitPHP\Auth\AbstractAdapter` is the quickest
path — it ships a sensible default `collective()` that iterates `set()`,
so you only have to implement the operations that matter.

## Goal

Back auth state with a real data store (a database, Redis, JWT, your
favourite key/value service) while still benefiting from the
`Segment` facade and the `AdapterInterface` contract.

## A minimal working adapter

```php
<?php

declare(strict_types=1);

namespace App\Auth;

use InitPHP\Auth\AbstractAdapter;
use InitPHP\Auth\AdapterInterface;

final class ArrayAdapter extends AbstractAdapter
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, $default = null)
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, $value): AdapterInterface
    {
        $this->store[$key] = $value;
        return $this;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->store);
    }

    public function remove(string ...$key): AdapterInterface
    {
        foreach ($key as $name) {
            unset($this->store[$name]);
        }
        return $this;
    }

    public function destroy(): bool
    {
        $this->store = [];
        return true;
    }
}
```

`collective()` is not implemented — the parent class provides a default
that calls `set()` per pair. Override it if your backing store can
commit atomically (the way `CookieAdapter` overrides it to emit one
`Set-Cookie` header for a bulk write).

## Using it through `Segment`

```php
use InitPHP\Auth\Segment;

$auth = Segment::custom('auth', App\Auth\ArrayAdapter::class);

$auth->set('user_id', 42);
$auth->get('user_id');  // 42
```

The custom factory requires the class to extend `AbstractAdapter`.
Passing a class that does not raises `InvalidArgumentException` — see
[Exceptions](../README.md#exceptions).

## Constructor signature

`AdapterInterface` deliberately does **not** include a constructor.
Different backing stores need different dependencies: a salt, a PDO
handle, a Redis client. Sign your constructor however the store needs.

The `Segment::custom()` factory will, however, invoke your constructor
as `new YourClass($name, $options)`, so the convention if you want it
to be `Segment`-compatible is to accept those two arguments.

```php
final class DatabaseAdapter extends AbstractAdapter
{
    private \PDO $pdo;
    private string $segment;

    /**
     * @param array{pdo: \PDO} $options
     */
    public function __construct(string $name, array $options)
    {
        if (!isset($options['pdo']) || !$options['pdo'] instanceof \PDO) {
            throw new \InvalidArgumentException('A PDO handle is required.');
        }
        $this->segment = $name;
        $this->pdo = $options['pdo'];
    }

    // ... get/set/has/remove/destroy ...
}

$auth = Segment::custom('auth', App\Auth\DatabaseAdapter::class, [
    'pdo' => $pdo,
]);
```

## A safer PDO-backed example

The v1 README shipped a `BasicAuthAdapter` sample that concatenated
user input into SQL strings. That example is replaced here with a
prepared-statement version that uses `password_verify()` and never
trusts the request directly.

```php
<?php

declare(strict_types=1);

namespace App\Auth;

use InitPHP\Auth\AbstractAdapter;
use InitPHP\Auth\AdapterInterface;
use PDO;

/**
 * Looks up a user by HTTP Basic credentials, then exposes the row as
 * an in-memory auth segment. Mutations are written back to the `users`
 * table with prepared statements.
 *
 * @phpstan-type UserRow array{
 *     id: int,
 *     username: string,
 *     password_hash: string,
 *     role?: string|null
 * }
 */
final class BasicAuthAdapter extends AbstractAdapter
{
    private const ALLOWED_COLUMNS = ['role'];

    private PDO $pdo;
    /** @var UserRow */
    private array $user;

    /**
     * @param array{dsn: string, username: string, password: string} $options
     */
    public function __construct(string $name, array $options)
    {
        $this->pdo = new PDO($options['dsn'], $options['username'], $options['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->user = $this->authenticate();
    }

    public function get(string $key, $default = null)
    {
        return $this->user[$key] ?? $default;
    }

    public function set(string $key, $value): AdapterInterface
    {
        $this->guardWritableColumn($key);

        $stmt = $this->pdo->prepare(
            // Column name is whitelisted above; the value is bound.
            \sprintf('UPDATE users SET %s = :value WHERE id = :id', $key)
        );
        $stmt->execute([
            ':value' => $value,
            ':id'    => $this->user['id'],
        ]);
        $this->user[$key] = $value;

        return $this;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->user);
    }

    public function remove(string ...$key): AdapterInterface
    {
        foreach ($key as $column) {
            $this->guardWritableColumn($column);
            $stmt = $this->pdo->prepare(
                \sprintf('UPDATE users SET %s = NULL WHERE id = :id', $column)
            );
            $stmt->execute([':id' => $this->user['id']]);
            unset($this->user[$column]);
        }
        return $this;
    }

    public function destroy(): bool
    {
        $this->user = ['id' => 0, 'username' => '', 'password_hash' => ''];
        return true;
    }

    /**
     * @return UserRow
     */
    private function authenticate(): array
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? '';
        $password = $_SERVER['PHP_AUTH_PW']   ?? '';

        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        /** @var UserRow|false $row */
        $row = $stmt->fetch();

        if ($row === false || !\password_verify($password, $row['password_hash'])) {
            \header('WWW-Authenticate: Basic realm="Private Area"');
            \header('HTTP/1.1 401 Unauthorized');
            exit('Authentication required.');
        }

        return $row;
    }

    private function guardWritableColumn(string $column): void
    {
        if (!\in_array($column, self::ALLOWED_COLUMNS, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Column "%s" is not writable through %s.',
                $column,
                self::class
            ));
        }
    }
}
```

Key differences from the v1 example:

- **No string concatenation of values.** Every value is bound through a
  prepared statement.
- **No string concatenation of column names either.** Column names that
  PDO cannot bind are whitelisted in `ALLOWED_COLUMNS`; anything else
  raises an exception before the SQL is built.
- **`password_verify()` instead of `md5()`.** Stored hashes should come
  from `password_hash($plain, PASSWORD_DEFAULT)` at registration time.
- **Strict PDO mode.** `ERRMODE_EXCEPTION` + `EMULATE_PREPARES=false`
  means PDO will refuse to silently degrade.

## Common mistakes

- **Implementing the interface directly without `AbstractAdapter`.**
  You can — but you lose the default `collective()` and have to
  implement six methods instead of five.
- **Throwing from `destroy()`.** Callers expect `destroy()` to be
  idempotent. Return `true` once the store is clean and let subsequent
  reads fail with `RuntimeException` via your `getStore()` guard.
- **Forwarding raw user input as SQL column names.** Always whitelist.
  Prepared statements bind values, not identifiers.
