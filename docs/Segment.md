# Segment

`InitPHP\Auth\Segment` is the facade you reach for in application code.
It does three things:

1. **Resolves an adapter** from a class name or one of the `ADAPTER_*`
   constants, so callers do not have to wire up the concrete adapter
   themselves.
2. **Implements [`AdapterInterface`](Adapter-Interface.md)** itself, so
   the facade is a drop-in replacement wherever the interface is
   expected.
3. **Forwards** the six contract methods to the underlying adapter and
   exposes an `adapter()` escape hatch for implementation-specific
   calls.

## Factory methods

The typed factories are the v2-recommended entry points:

```php
use InitPHP\Auth\Segment;

Segment::session('auth');                                  // SessionAdapter
Segment::cookie('auth', ['salt' => $secret]);              // CookieAdapter
Segment::custom('auth', App\Auth\DatabaseAdapter::class);  // custom adapter
```

The legacy v1 factory accepts an `int|string` adapter and is kept for
backwards compatibility:

```php
Segment::create('auth', Segment::ADAPTER_SESSION);
Segment::create('auth', Segment::ADAPTER_COOKIE, ['salt' => $secret]);
Segment::create('auth', App\Auth\DatabaseAdapter::class);

new Segment('auth', Segment::ADAPTER_SESSION);
new Segment('auth', App\Auth\DatabaseAdapter::class, ['pdo' => $pdo]);
```

| Constant | Value | Equivalent factory |
| --- | --- | --- |
| `Segment::ADAPTER_SESSION` | `0` | `Segment::session($name, $options)` |
| `Segment::ADAPTER_COOKIE`  | `1` | `Segment::cookie($name, $options)` |

## The proxy methods

`Segment` implements `AdapterInterface`, so each contract method has a
dedicated proxy:

```php
$segment->get(string $key, mixed $default = null): mixed
$segment->set(string $key, mixed $value): AdapterInterface
$segment->collective(array $data): AdapterInterface
$segment->has(string $key): bool
$segment->remove(string ...$keys): AdapterInterface
$segment->destroy(): bool
```

`set()`, `collective()`, and `remove()` return `$this` (the `Segment`),
not the underlying adapter, so chained calls keep flowing through the
facade:

```php
$segment->set('user_id', 42)
        ->set('role', 'editor')
        ->remove('legacy_flag');
```

## Reaching the concrete adapter

For implementation-specific methods that are not part of
`AdapterInterface` (e.g. a custom adapter that exposes
`refreshToken(string $reason): string`), use either of these:

```php
// 1. The explicit escape hatch — preferred for static analysis.
$segment->adapter();                       // returns AdapterInterface
$segment->adapter()->refreshToken('exp');

// 2. The __call magic delegation — handy for ad-hoc calls.
$segment->refreshToken('exp');
```

`__call()` forwards verbatim. A method that does not exist on the
adapter raises the standard PHP "Call to undefined method" `Error`:

```php
$segment->totallyMadeUp();
// Error: Call to undefined method ...::totallyMadeUp()
```

## Adapter resolution rules

`Segment::custom()` and the generic constructor accept a FQCN string.
The resolver applies these checks in order:

1. The class must exist (`class_exists()`).
2. The class must extend [`AbstractAdapter`](Adapter-Interface.md#abstractadapter).
3. It is instantiated with `new $class($name, $options)`.

Failure modes all raise `InvalidArgumentException` with a message that
names the failing case:

```php
new Segment('auth', 999);
// InvalidArgumentException:
//   Unknown adapter constant: 999. Expected ADAPTER_SESSION (0) or ADAPTER_COOKIE (1).

new Segment('auth', 'App\\No\\Such\\Class');
// InvalidArgumentException: Adapter class "App\No\Such\Class" does not exist.

new Segment('auth', stdClass::class);
// InvalidArgumentException: Adapter class "stdClass" must extend InitPHP\Auth\AbstractAdapter.

new Segment('auth', 1.5);
// InvalidArgumentException:
//   $adapter must be one of the ADAPTER_* constants or a class name that extends InitPHP\Auth\AbstractAdapter.
```

See [Exceptions](Exceptions.md) for the full list.

## Constructor signature for custom adapters

When `Segment` instantiates your custom adapter it calls:

```php
new YourAdapter($name, $options);
```

If your adapter needs more constructor arguments (a `PDO` handle, a
Redis client), pass them through `$options` and unpack them inside
your constructor. See [Custom Adapters](Custom-Adapters.md) for a worked
example.

```php
$segment = Segment::custom('auth', App\Auth\DatabaseAdapter::class, [
    'pdo' => $pdo,
]);
```

If your adapter does **not** follow the `(string $name, array $options)`
convention, do not use `Segment::custom()`. Instantiate it directly and
hand-build the segment:

```php
$adapter = new App\Auth\WeirdAdapter($somethingElse);

// Wrap it manually — Segment does not currently accept a pre-built adapter
// in its public surface, so most callers will just use $adapter directly.
```

## A note on configuration forwarding

The `$options` array is passed straight to the adapter constructor.
The package does not split, filter, or merge it. Each adapter
documents its own option matrix:

- [Session Adapter → Constructor options](Session-Adapter.md#constructor-options)
- [Cookie Adapter → Constructor options](Cookie-Adapter.md#constructor-options)
- [Custom Adapters → Constructor signature](Custom-Adapters.md#constructor-signature)

## Common mistakes

- **Calling `$segment->adapter()->set(...)`.** That works but breaks
  the chain — `$segment->set(...)` is the same thing and returns the
  facade, not the inner adapter, so subsequent calls go through the
  same proxy.
- **Calling `new Segment` with a class string that does not extend
  `AbstractAdapter`.** The error message tells you exactly what to fix
  — extend `AbstractAdapter`, not just implement the interface, when
  you want `Segment::custom()` compatibility.
- **Passing a relative class name.** Always pass a FQCN (e.g.
  `App\Auth\DatabaseAdapter::class`). The resolver does not search
  namespaces.

## Where to go next

- [Adapter Interface](Adapter-Interface.md) — the contract behind the
  facade.
- [Custom Adapters](Custom-Adapters.md) — implementing your own.
- [API Reference](API-Reference.md#segment) — full method signatures.
