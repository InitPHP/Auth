# Cookie Writer

`InitPHP\Auth\Cookie\CookieWriterInterface` is a thin abstraction over
PHP's `setcookie()`. It exists so [`CookieAdapter`](Cookie-Adapter.md) is
exercisable in unit tests without touching response headers, and so
that callers who do not run on a "raw" PHP SAPI (PSR-7 stacks,
ReactPHP, RoadRunner) can route cookies through their own response
machinery.

## The interface

```php
namespace InitPHP\Auth\Cookie;

interface CookieWriterInterface
{
    /**
     * @param array<string, mixed> $options Same shape accepted by PHP's
     *                                      setcookie() options array
     *                                      (expires, path, domain, secure,
     *                                      httponly, samesite).
     *
     * @return bool True when the header was queued for delivery, false
     *              when output had already been started or the cookie was
     *              rejected.
     */
    public function send(string $name, string $value, array $options): bool;
}
```

A single method — `send()`. Deletion is modelled as a normal `send()`
with an `expires` in the past; the caller is responsible for building
the correct options array (because RFC 6265 requires path/domain to
match the original cookie).

## NativeCookieWriter

The default writer used by `CookieAdapter` when no other writer is
supplied. Delegates verbatim to `setcookie()`:

```php
namespace InitPHP\Auth\Cookie;

final class NativeCookieWriter implements CookieWriterInterface
{
    public function send(string $name, string $value, array $options): bool
    {
        return \setcookie($name, $value, $options);
    }
}
```

You will almost never instantiate this directly — `CookieAdapter`
constructs one when its third constructor argument is `null` (the
default).

## InMemoryCookieWriter

The test double. Records every `send()` call instead of emitting a
header, and lets you assert on the recorded calls afterwards.

```php
namespace InitPHP\Auth\Cookie;

final class InMemoryCookieWriter implements CookieWriterInterface
{
    public function send(string $name, string $value, array $options): bool;
    public function calls(): array;                  // every recorded call
    public function lastCall(): ?array;              // most recent call, or null
    public function reset(): void;                   // clear the log
    public function returnValue(bool $value): void;  // simulate a writer failure
}
```

Each call is recorded as:

```php
[
    'name'    => string,
    'value'   => string,
    'options' => array<string, mixed>,
]
```

### Use case 1: assert on the emitted cookie

```php
use InitPHP\Auth\Cookie\InMemoryCookieWriter;
use InitPHP\Auth\CookieAdapter;

$writer  = new InMemoryCookieWriter();
$adapter = new CookieAdapter('auth', ['salt' => $secret], $writer);

$adapter->set('user_id', 42);

$last = $writer->lastCall();
self::assertSame('auth', $last['name']);
self::assertTrue($last['options']['secure']);
self::assertSame('Lax', $last['options']['samesite']);
```

### Use case 2: assert that `collective()` emits one header

```php
$writer  = new InMemoryCookieWriter();
$adapter = new CookieAdapter('auth', ['salt' => $secret], $writer);

$adapter->collective(['a' => 1, 'b' => 2, 'c' => 3]);

self::assertCount(1, $writer->calls());   // one Set-Cookie, not three
```

### Use case 3: simulate a writer failure

`setcookie()` returns `false` when output has already begun. To exercise
your error path without breaking the test runner, force the in-memory
writer to return false:

```php
$writer = new InMemoryCookieWriter();
$writer->returnValue(false);

$adapter = new CookieAdapter('auth', ['salt' => $secret], $writer);
self::assertFalse($adapter->destroy());
```

### Use case 4: cookie round-trip without touching `$_COOKIE`

Combine the in-memory writer with a manual `$_COOKIE` injection to test
the full encode → wire → decode loop:

```php
$writer  = new InMemoryCookieWriter();
$writer1 = new CookieAdapter('auth', ['salt' => $secret], $writer);
$writer1->set('user_id', 42);

$_COOKIE['auth'] = $writer->lastCall()['value'];

$reader = new CookieAdapter('auth', ['salt' => $secret], new InMemoryCookieWriter());
self::assertSame(42, $reader->get('user_id'));
```

## Writing your own writer

Any class that implements `CookieWriterInterface` is acceptable. The
typical reason to write one is to route cookies through a PSR-7
response object instead of PHP's response header buffer.

```php
use InitPHP\Auth\Cookie\CookieWriterInterface;
use Psr\Http\Message\ResponseInterface;

final class Psr7CookieWriter implements CookieWriterInterface
{
    private ResponseInterface $response;

    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
    }

    public function send(string $name, string $value, array $options): bool
    {
        $cookie = sprintf('%s=%s', urlencode($name), urlencode($value));
        if (!empty($options['expires'])) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s T', (int) $options['expires']);
        }
        if (!empty($options['path']))     { $cookie .= '; Path='     . $options['path']; }
        if (!empty($options['domain']))   { $cookie .= '; Domain='   . $options['domain']; }
        if (!empty($options['secure']))   { $cookie .= '; Secure'; }
        if (!empty($options['httponly'])) { $cookie .= '; HttpOnly'; }
        if (!empty($options['samesite'])) { $cookie .= '; SameSite=' . $options['samesite']; }

        $this->response = $this->response->withAddedHeader('Set-Cookie', $cookie);
        return true;
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }
}
```

Then plug it into the adapter:

```php
$writer  = new Psr7CookieWriter($response);
$adapter = new CookieAdapter('auth', ['salt' => $secret], $writer);

// ... drive $adapter ...

$response = $writer->response();
```

> The snippet above is a 30-line illustration, not a hardened
> implementation. For production PSR-7 cookie handling, lean on a
> dedicated library such as `dflydev/fig-cookies` and adapt its
> `SetCookie` builder.

## Common mistakes

- **Returning `true` unconditionally from your writer's `send()`.** The
  return value is what `CookieAdapter::destroy()` propagates. Lying
  about success makes failures invisible.
- **Mutating the `$options` array in place.** Treat it as read-only;
  the adapter relies on it staying consistent between successive
  `send()` calls (notably between `set()` and `destroy()`).
- **Storing the writer instance on a request-scoped object and reusing
  it across requests.** `setcookie()`-backed writers are stateless,
  but PSR-7 or PSR-15 wrappers usually own a `ResponseInterface` that
  belongs to the current request only.

## Where to go next

- [Cookie Adapter](Cookie-Adapter.md) — the consumer of this interface.
- [Testing](Testing.md) — full patterns for in-memory writers.
- [API Reference](API-Reference.md#cookie-writer) — class member list.
