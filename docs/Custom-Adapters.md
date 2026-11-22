# Custom Adapters

Anything that implements [`AdapterInterface`](Adapter-Interface.md) can
sit behind a [`Segment`](Segment.md). Extending
[`AbstractAdapter`](Adapter-Interface.md#abstractadapter) is the quickest
path — it ships a sensible default `collective()` that iterates
`set()`, so you only have to implement the operations that matter.

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
that calls `set()` per pair. Override it when your backing store can
commit atomically and the per-key write would be wasteful (the cookie
adapter does this so a bulk write emits one `Set-Cookie` header
instead of N).

## Wiring it through `Segment`

```php
use InitPHP\Auth\Segment;

$auth = Segment::custom('auth', App\Auth\ArrayAdapter::class);

$auth->set('user_id', 42);
$auth->get('user_id');   // 42
```

The custom factory requires the class to extend `AbstractAdapter`.
Passing a class that does not raises `InvalidArgumentException` — see
[Exceptions](Exceptions.md).

## Constructor signature

`AdapterInterface` deliberately does **not** include a constructor.
Different backing stores need different dependencies: a salt, a PDO
handle, a Redis client. Sign your constructor however the store needs.

The `Segment::custom()` factory will, however, invoke your constructor
as `new YourClass($name, $options)`, so the convention if you want it
to be `Segment`-compatible is to accept those two arguments:

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
        $this->pdo     = $options['pdo'];
    }

    // ... get/set/has/remove/destroy ...
}

$auth = Segment::custom('auth', App\Auth\DatabaseAdapter::class, [
    'pdo' => $pdo,
]);
```

If your adapter needs a constructor signature that does not fit
`(string $name, array $options)`, build it by hand:

```php
$adapter = new App\Auth\WeirdAdapter($somethingElse, $anotherDependency);

// Then consume $adapter directly — Segment does not currently accept a
// pre-built adapter through its public surface.
```

## A safer PDO-backed example

The v1 README shipped a `BasicAuthAdapter` sample that concatenated
user input into SQL strings and used `md5()` to compare passwords.
This is the rewritten version: prepared statements, `password_verify()`,
and a column-name whitelist.

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
 *     role?: string|null,
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

        // Column name is whitelisted above; the value is bound.
        $stmt = $this->pdo->prepare(
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

### Key differences from the v1 example

| Concern | v1 README | This page |
| --- | --- | --- |
| User input in SQL | Concatenated | Bound via prepared statement |
| Column names in SQL | Concatenated | Whitelisted in `ALLOWED_COLUMNS` |
| Password comparison | `md5()` | `password_verify()` against `password_hash()` output |
| PDO error mode | Default (silent) | `ERRMODE_EXCEPTION` + `EMULATE_PREPARES=false` |
| Fetch mode | Default | `FETCH_ASSOC` (typed array shape) |

The full request-lifecycle example (with `Permission` gating) lives in
the [Basic Auth Recipe](Recipe-Basic-Auth.md).

## Overriding `collective()`

The default implementation iterates `set()`. Override when you can do
better:

```php
public function collective(array $data): AdapterInterface
{
    $this->pdo->beginTransaction();
    try {
        foreach ($data as $key => $value) {
            $this->set((string) $key, $value);
        }
        $this->pdo->commit();
    } catch (\Throwable $e) {
        $this->pdo->rollBack();
        throw $e;
    }
    return $this;
}
```

(The above is illustrative; production code would also coalesce the
per-row `UPDATE` statements into a single batched query.)

## Validation: fail fast in the constructor

Surface bad configuration immediately, before any I/O:

```php
public function __construct(string $name, array $options)
{
    if (!isset($options['pdo']) || !$options['pdo'] instanceof \PDO) {
        throw new \InvalidArgumentException('A PDO handle is required.');
    }
    // ...
}
```

This matches the pattern `CookieAdapter` uses for the `salt` option,
and it keeps confusing "method called on null" errors out of your
production logs.

## Common mistakes

- **Implementing the interface directly without `AbstractAdapter`.**
  You can — but you lose the default `collective()` and have to
  implement six methods instead of five.
- **Throwing from `destroy()` on an already-destroyed store.**
  `destroy()` should be idempotent. Return `false` once the store is
  clean and let subsequent reads/writes hit the existing guard that
  throws.
- **Forwarding raw user input as SQL column names.** Always whitelist.
  Prepared statements bind values, not identifiers.
- **Hashing passwords with `md5()`/`sha1()`.** Use `password_hash()`
  at registration time and `password_verify()` at login. The cost
  parameter automatically tracks PHP defaults.
- **Storing the PDO connection on a long-lived adapter instance.** If
  the adapter outlives the request (queue worker, daemon), the
  underlying TCP connection will eventually be dropped by the server.
  Reconstruct the adapter per job.

## Where to go next

- [Adapter Interface](Adapter-Interface.md) — the contract you implement.
- [Segment](Segment.md) — how the facade hands work off to your adapter.
- [Recipe → HTTP Basic Auth](Recipe-Basic-Auth.md) — full request
  lifecycle around `BasicAuthAdapter`.
- [Testing](Testing.md) — how to test a custom adapter without a real
  database.
