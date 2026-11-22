# Adapter Interface

`InitPHP\Auth\AdapterInterface` is the storage contract every adapter
satisfies. Depend on the interface in your service signatures so callers
can substitute a different implementation (a session in production, a
[`NullAdapter`](Null-Adapter.md) in tests, a database adapter in CLI
scripts) without touching consumer code.

## The contract

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

> Constructors are **deliberately not** part of the contract. Different
> backing stores need different dependencies (a salt, a PDO handle, a
> Redis client) and forcing a single constructor signature would defeat
> the purpose of the abstraction.
>
> If you want [`Segment::custom()`](Segment.md#factory-methods) to
> instantiate your adapter for you, follow the convention
> `__construct(string $name, array $options = [])`. Otherwise, build the
> adapter yourself.

## Per-method semantics

### `get(string $key, mixed $default = null): mixed`

Return the value stored under `$key`, or `$default` when the key is
absent. A stored `null` counts as present and is returned verbatim —
use [`has()`](#hasstring-key-bool) when you need to distinguish "absent" from
"present-but-null".

### `set(string $key, mixed $value): static`

Assign `$value` to `$key`, overwriting any existing value. Returns
`$this` so calls chain.

### `collective(array $data): static`

Apply every `(key, value)` pair from `$data` in one logical operation.
Implementations are free to commit atomically — `CookieAdapter`, for
example, overrides the default to emit one `Set-Cookie` header per
`collective()` call instead of one per `set()`. The keys are
`string`-typed; non-string keys are coerced via `(string) $key` in the
default implementation.

### `has(string $key): bool`

Whether `$key` is present in the backing store. A stored `null` is
considered present — this is the authoritative existence check.

### `remove(string ...$keys): static`

Drop one or more keys. Missing keys are a no-op. Returns `$this` so
calls chain.

### `destroy(): bool`

Tear down the backing store. The return value reports whether anything
was actually torn down (true) or the store was already empty / never
written (false). Behaviour after `destroy()` is implementation-defined,
but every shipped adapter throws `\RuntimeException` on any subsequent
read or write.

## Return type covariance

The interface declares `: self` on the chained methods, but every
shipped adapter narrows the return to the concrete class through PHP's
covariant return rules:

```php
interface AdapterInterface
{
    public function set(string $key, $value): self;          // = AdapterInterface
}

class SessionAdapter extends AbstractAdapter
{
    public function set(string $key, $value): AdapterInterface;
}
```

If you implement the interface directly, you can return `static`, the
interface, or your own concrete type. PHPStan and IDEs follow whichever
you pick.

## AbstractAdapter

`InitPHP\Auth\AbstractAdapter` is the recommended base class. It
implements `AdapterInterface` and provides one piece of shared
behaviour:

```php
abstract class AbstractAdapter implements AdapterInterface
{
    public function collective(array $data): AdapterInterface
    {
        foreach ($data as $key => $value) {
            $this->set((string) $key, $value);
        }
        return $this;
    }
}
```

So a minimal custom adapter only has to implement five methods —
`get`, `set`, `has`, `remove`, `destroy`. Override `collective()` only
if your store can commit atomically and you want to avoid the
per-key write the default implements.

## Lifecycle expectations

The library assumes one adapter instance per "logical store, per
request". Shared instances across requests, processes, or long-running
workers are not supported by the shipped adapters — `SessionAdapter`
holds a reference to a per-request `ParameterBag` snapshot,
`CookieAdapter` mutates a `setcookie()` queue, and `NullAdapter` is
stateless.

| Phase | Expected behaviour |
| --- | --- |
| Construction | Validate inputs; surface bad configuration as `InvalidArgumentException` before any I/O. |
| Reads | Idempotent; do not mutate the store. |
| Writes | Update an in-memory snapshot and commit to the store. |
| `destroy()` | Tear down the store; subsequent operations may throw. |

## Implementing the interface

You have two options:

### 1. Extend `AbstractAdapter` (preferred)

```php
use InitPHP\Auth\AbstractAdapter;
use InitPHP\Auth\AdapterInterface;

final class ArrayAdapter extends AbstractAdapter
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, $default = null)        { return $this->store[$key] ?? $default; }
    public function set(string $key, $value): AdapterInterface { $this->store[$key] = $value; return $this; }
    public function has(string $key): bool                   { return \array_key_exists($key, $this->store); }
    public function remove(string ...$key): AdapterInterface { foreach ($key as $k) unset($this->store[$k]); return $this; }
    public function destroy(): bool                          { $this->store = []; return true; }
}
```

This gets you the default `collective()` for free and is what
[`Segment::custom()`](Segment.md#factory-methods) expects.

### 2. Implement `AdapterInterface` directly

Only do this when you cannot extend `AbstractAdapter` for some reason
(you already extend another base class, you need a different
`collective()` signature, etc.). You will have to implement all six
methods yourself.

## Common mistakes

- **Returning the bare interface from chained methods.** Returning
  `AdapterInterface` is correct but throws away your concrete type.
  Return your own class (`AdapterInterface` is satisfied through
  covariance) or `static` for the best IDE experience.
- **Throwing from `destroy()` on an already-destroyed store.**
  `destroy()` should be idempotent. Return `false` on the second call
  and let subsequent reads/writes hit the existing guard that throws.
- **Skipping construction-time validation.** A bad `salt` or a missing
  PDO handle should surface immediately, not on the first `set()`.
  See `CookieAdapter::__construct()` for the pattern.

## Where to go next

- [Custom Adapters](Custom-Adapters.md) — full worked example.
- [Session Adapter](Session-Adapter.md) and
  [Cookie Adapter](Cookie-Adapter.md) — the bundled implementations.
- [API Reference](API-Reference.md#adapterinterface) — full method
  signatures.
