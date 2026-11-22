# API Reference

The full public surface of every type shipped by `initphp/auth`.
Behavioural deep-dives are linked into the [Core Types](Permissions.md)
and [Adapters](Session-Adapter.md) sections.

> Signatures below show the **PHP 8** form for readability. The
> library is type-safe under PHPStan level 8.

---

## `AdapterInterface`

```php
namespace InitPHP\Auth;

interface AdapterInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): self;
    public function collective(array $data): self;
    public function has(string $key): bool;
    public function remove(string ...$keys): self;
    public function destroy(): bool;
}
```

Depend on this interface in your service signatures. See
[Adapter Interface](Adapter-Interface.md) for per-method semantics.

---

## `AbstractAdapter`

```php
namespace InitPHP\Auth;

abstract class AbstractAdapter implements AdapterInterface
{
    public function collective(array $data): AdapterInterface;
}
```

The default `collective()` iterates `set()`. Override for atomic
backing stores. See [Adapter Interface →
AbstractAdapter](Adapter-Interface.md#abstractadapter).

---

## `Segment`

The facade that wraps a single adapter and forwards the
`AdapterInterface` contract methods.

```php
namespace InitPHP\Auth;

class Segment implements AdapterInterface
{
    public const ADAPTER_SESSION = 0;
    public const ADAPTER_COOKIE  = 1;

    public function __construct(string $name, int|string $adapter = self::ADAPTER_SESSION, array $options = []);

    // Factories
    public static function create(string $name, int|string $adapter = self::ADAPTER_SESSION, array $options = []): self;
    public static function session(string $name, array $options = []): self;
    public static function cookie(string $name, array $options = []): self;
    public static function custom(string $name, string $adapterClass, array $options = []): self;

    // Escape hatch
    public function adapter(): AdapterInterface;

    // AdapterInterface proxies
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): AdapterInterface;
    public function collective(array $data): AdapterInterface;
    public function has(string $key): bool;
    public function remove(string ...$keys): AdapterInterface;
    public function destroy(): bool;

    // Magic delegation for non-interface adapter methods
    public function __call(string $name, array $arguments): mixed;
}
```

See [Segment](Segment.md) for the factory comparison, BC notes, and
adapter resolution rules.

---

## `SessionAdapter`

```php
namespace InitPHP\Auth;

class SessionAdapter extends AbstractAdapter
{
    public function __construct(string $name, array $options = []);
}
```

Throws `\RuntimeException` if `session_status() !== PHP_SESSION_ACTIVE`
at construction time, or on any read/write after `destroy()`.

See [Session Adapter](Session-Adapter.md) for the full lifecycle and the
options forwarded to the internal `ParameterBag`.

---

## `CookieAdapter`

```php
namespace InitPHP\Auth;

class CookieAdapter extends AbstractAdapter
{
    public const MIN_SALT_LENGTH = 32;

    public function __construct(
        string $name,
        array $options = [],
        ?\InitPHP\Auth\Cookie\CookieWriterInterface $writer = null,
    );
}
```

Overrides `collective()` so a bulk write emits one `Set-Cookie` header
instead of N.

Throws `\InvalidArgumentException` for:

- missing / non-string / short `salt`;
- `samesite='None' + secure=false`.

Throws `\RuntimeException` on any read/write after `destroy()`, and on
JSON encoding failure inside `set()` / `collective()`.

See [Cookie Adapter](Cookie-Adapter.md) for the wire format and the
options matrix.

---

## `NullAdapter`

```php
namespace InitPHP\Auth;

final class NullAdapter extends AbstractAdapter
{
    public function __construct(string $name = '', array $options = []);
}
```

Every operation is a no-op (or returns the default value / `false` /
`true` as appropriate). See [Null Adapter](Null-Adapter.md).

---

## Cookie Writer

```php
namespace InitPHP\Auth\Cookie;

interface CookieWriterInterface
{
    public function send(string $name, string $value, array $options): bool;
}

final class NativeCookieWriter implements CookieWriterInterface
{
    public function send(string $name, string $value, array $options): bool;
}

final class InMemoryCookieWriter implements CookieWriterInterface
{
    public function send(string $name, string $value, array $options): bool;
    public function calls(): array;
    public function lastCall(): ?array;
    public function reset(): void;
    public function returnValue(bool $value): void;
}
```

See [Cookie Writer](Cookie-Writer.md) for the full discussion.

---

## `Permission`

```php
namespace InitPHP\Auth;

class Permission
{
    public function __construct(array $permissions = []);

    public function is(string ...$names): bool;
    public function push(string ...$names): int;
    public function remove(string ...$names): int;

    public function getPermissions(): array;
    public function getPermission(): array;   // @deprecated since 2.0

    // Magic
    public function __call(string $name, array $arguments): bool;
    public function __isset(string $name): bool;
    public function __unset(string $name): void;
    public function __sleep(): array;
}
```

`__call()` raises `\BadMethodCallException` for names that do not start
with `is_`. See [Permissions](Permissions.md) for the magic accessor
table and the normalization rules.

---

## Exception map

| Exception class | Raised by |
| --- | --- |
| `\InvalidArgumentException` | `Segment::__construct/create/custom`, `CookieAdapter::__construct` |
| `\RuntimeException` | `SessionAdapter::__construct` (inactive session), any adapter read/write after `destroy()`, `CookieAdapter::set/collective` on JSON encoding failure |
| `\BadMethodCallException` | `Permission::__call` for non-`is_*` names |
| `\Error` | `Segment::__call` when the underlying adapter does not expose the method |

See [Exceptions](Exceptions.md) for the per-failure-mode table.
