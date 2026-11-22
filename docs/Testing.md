# Testing

The package was designed to be easy to test against — the shipped
test suite covers 78 cases at PHPStan level 8. This page documents
the patterns used in those tests so you can lift them straight into
your own code.

## Setting up PHPUnit for the session adapter

PHP's CLI SAPI does not seed `session.save_path`. Without it,
`session_start()` silently bails and every `SessionAdapter` test
fails with `"Sessions must be started."`

Add this snippet to your `phpunit.xml.dist`:

```xml
<php>
    <ini name="session.save_handler" value="files"/>
    <ini name="session.save_path"    value="/tmp"/>
    <ini name="output_buffering"     value="4096"/>
</php>
```

The `output_buffering` directive matters too: PHPUnit's dot-progress
writer flushes to stdout between tests, which sends headers, which
breaks the next `session_start()` call with `"Cannot start session
after headers already sent"`. Output buffering keeps the headers
deferred.

## Session tests

Run each `SessionAdapter` test in its own process so that
`session_status()` resets cleanly between cases:

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use InitPHP\Auth\SessionAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SessionAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION = [];
    }

    public function testConstructorRefusesToOperateWithoutActiveSession(): void
    {
        // The class-level setUp() always starts a session; close it
        // back down so the adapter sees PHP_SESSION_NONE.
        \session_write_close();

        $this->expectException(RuntimeException::class);
        new SessionAdapter('auth');
    }

    public function testSetPersistsToSessionSuperglobal(): void
    {
        $adapter = new SessionAdapter('auth');
        $adapter->set('user_id', 42);

        self::assertSame(['user_id' => 42], $_SESSION['auth']);
    }
}
```

The cost is real (each separate-process test runs in ~30 ms), but it
buys you full isolation.

## Cookie tests with InMemoryCookieWriter

`CookieAdapter` accepts a third constructor argument: a
[`CookieWriterInterface`](Cookie-Writer.md). The default writer delegates
to `setcookie()`; the in-memory writer records every call instead.

```php
use InitPHP\Auth\Cookie\InMemoryCookieWriter;
use InitPHP\Auth\CookieAdapter;
use PHPUnit\Framework\TestCase;

final class CookieAdapterTest extends TestCase
{
    private const VALID_SALT = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $_COOKIE = [];
    }

    public function testSetEmitsOneSignedCookie(): void
    {
        $writer  = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);

        $adapter->set('user_id', 42);

        $last = $writer->lastCall();
        self::assertNotNull($last);
        self::assertSame('auth', $last['name']);
        self::assertStringContainsString('.', $last['value']);  // base64url . hmac
        self::assertTrue($last['options']['secure']);
        self::assertSame('Lax', $last['options']['samesite']);
    }
}
```

The writer keeps a list of every call; assert on it directly.

## Cookie round-trip without touching `$_COOKIE`

To exercise the full encode → wire → decode loop, capture the cookie
value from the in-memory writer and inject it into `$_COOKIE` manually:

```php
$writer  = new InMemoryCookieWriter();
$writer1 = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);
$writer1->set('user_id', 42);

$_COOKIE['auth'] = $writer->lastCall()['value'];

$reader = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
self::assertSame(42, $reader->get('user_id'));
```

## Asserting that tampered cookies are rejected

Flip a byte in the signature and assert that the decoder yields an
empty bag:

```php
$writer = new InMemoryCookieWriter();
(new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer))->set('user_id', 42);
$cookie = $writer->lastCall()['value'];

// Flip the last character — with overwhelming probability the
// signature no longer verifies.
$tampered        = \substr($cookie, 0, -1) . (\substr($cookie, -1) === 'a' ? 'b' : 'a');
$_COOKIE['auth'] = $tampered;

$adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
self::assertNull($adapter->get('user_id'));
```

## Asserting that `destroy()` reuses the original attributes

This is the regression test for the v1 deletion bug — if you write your
own cookie-aware code path, mirror it:

```php
$writer  = new InMemoryCookieWriter();
$adapter = new CookieAdapter('auth', [
    'salt'   => self::VALID_SALT,
    'path'   => '/admin',
    'domain' => 'example.com',
], $writer);

$adapter->destroy();

$last = $writer->lastCall();
self::assertSame('/admin', $last['options']['path']);
self::assertSame('example.com', $last['options']['domain']);
self::assertSame('Lax', $last['options']['samesite']);
self::assertTrue($last['options']['secure']);
self::assertLessThan(\time(), $last['options']['expires']);
```

## Simulating writer failures

`setcookie()` returns `false` when output has already begun. Force the
in-memory writer to mimic that so you can exercise your error path:

```php
$writer = new InMemoryCookieWriter();
$writer->returnValue(false);

$adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);
self::assertFalse($adapter->destroy());   // surfaced through destroy()'s bool return
```

## Segment::custom with a RecordingAdapter

When you want to test code that consumes a `Segment` without spinning
up a real session or cookie store, ship a fixture adapter that records
every call:

```php
namespace App\Tests\Fixture;

use InitPHP\Auth\AbstractAdapter;
use InitPHP\Auth\AdapterInterface;

final class RecordingAdapter extends AbstractAdapter
{
    /** @var list<array{method: string, args: array<int|string, mixed>}> */
    public array $calls = [];

    public string $constructorName = '';
    /** @var array<string, mixed> */
    public array $constructorOptions = [];

    public function __construct(string $name = '', array $options = [])
    {
        $this->constructorName    = $name;
        $this->constructorOptions = $options;
    }

    public function get(string $key, $default = null)
    {
        $this->calls[] = ['method' => 'get', 'args' => [$key, $default]];
        return 'recorded:' . $key;
    }

    public function set(string $key, $value): AdapterInterface
    {
        $this->calls[] = ['method' => 'set', 'args' => [$key, $value]];
        return $this;
    }

    public function has(string $key): bool
    {
        $this->calls[] = ['method' => 'has', 'args' => [$key]];
        return true;
    }

    public function remove(string ...$key): AdapterInterface
    {
        $this->calls[] = ['method' => 'remove', 'args' => $key];
        return $this;
    }

    public function destroy(): bool
    {
        $this->calls[] = ['method' => 'destroy', 'args' => []];
        return true;
    }
}
```

Then test against the recorded calls:

```php
use InitPHP\Auth\Segment;
use App\Tests\Fixture\RecordingAdapter;

$segment = Segment::custom('auth', RecordingAdapter::class);
/** @var RecordingAdapter $adapter */
$adapter = $segment->adapter();

$segment->set('user_id', 42);

$expected = [
    ['method' => 'set', 'args' => ['user_id', 42]],
];
self::assertSame($expected, $adapter->calls);
```

## Picking the right test double

| Goal | Pick |
| --- | --- |
| The code under test calls an adapter, you do not care which | [`NullAdapter`](Null-Adapter.md) |
| You want to assert that specific calls happened | `RecordingAdapter` (above) or `createMock(AdapterInterface::class)` |
| You want to assert on the cookie that would be emitted | `InMemoryCookieWriter` |
| You want to exercise real `$_SESSION` plumbing | Real `SessionAdapter` + `@runTestsInSeparateProcesses` |

## Common mistakes

- **Forgetting `@runTestsInSeparateProcesses` on session tests.** PHP
  keeps `session_status()` at process scope; without isolation, one
  test's `session_start()` leaks into the next test's
  "session must be inactive" assertion.
- **Asserting on `setcookie()` directly.** The headers PHP buffers in
  tests are not part of the request lifecycle and are awkward to
  inspect. Use `InMemoryCookieWriter` — your assertions become trivial
  array comparisons.
- **Reusing the in-memory writer across tests.** It accumulates calls.
  Either instantiate a fresh writer per test, or call `reset()` in
  `setUp()`.
- **Mocking the entire `AdapterInterface` when you really want a
  recorder.** PHPUnit mocks are fine, but the explicit fixture above
  reads better in failing test output (`['method' => ...]` vs.
  `Expectation failed for method name 'set'`).

## Where to go next

- [Cookie Writer](Cookie-Writer.md) — full `InMemoryCookieWriter` API.
- [Null Adapter](Null-Adapter.md) — when you genuinely want a stub that
  records nothing.
- [Custom Adapters](Custom-Adapters.md) — how to write your own adapter
  and test it without a real backing store.
