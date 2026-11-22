# Null Adapter

`InitPHP\Auth\NullAdapter` is a
[Null Object](https://en.wikipedia.org/wiki/Null_object_pattern)
implementation of [`AdapterInterface`](Adapter-Interface.md). Every
operation succeeds and stores nothing.

## When to pick it

- You have code that expects an `AdapterInterface` and you do not
  want to materialise a real backing store. Examples:
  - **Unit tests** where the adapter is a collaborator but its
    behaviour is irrelevant to the test.
  - **CLI scripts** where there is no session and no client cookie.
  - **Feature flags** — wrap the real adapter behind a check and fall
    back to `NullAdapter` when the feature is off.

## Behaviour

```php
use InitPHP\Auth\NullAdapter;

$adapter = new NullAdapter();

$adapter->set('user_id', 42);
$adapter->get('user_id');                 // null
$adapter->get('user_id', 'fallback');     // 'fallback'
$adapter->has('user_id');                 // false
$adapter->remove('user_id');              // returns $this
$adapter->destroy();                      // true
```

Method by method:

| Method | Returns |
| --- | --- |
| `get($key, $default = null)` | `$default` (always). |
| `set($key, $value)` | `$this` (no-op). |
| `collective($data)` | `$this` (no-op). |
| `has($key)` | `false` (always). |
| `remove(...$keys)` | `$this` (no-op). |
| `destroy()` | `true`. |

The class is `final` and takes an optional `(string $name = '', array $options = [])`
constructor signature for parity with the other adapters. Both
arguments are accepted and ignored.

## Behind a feature flag

A common pattern: hide an experiment behind a flag and use
`NullAdapter` when the flag is off:

```php
use InitPHP\Auth\AdapterInterface;
use InitPHP\Auth\NullAdapter;
use InitPHP\Auth\Segment;

function authAdapter(bool $featureEnabled): AdapterInterface
{
    if (!$featureEnabled) {
        return new NullAdapter();
    }
    return Segment::cookie('auth', ['salt' => $_ENV['AUTH_COOKIE_SECRET']]);
}
```

Now the consumer code is the same whether the feature is on or off:

```php
$auth = authAdapter($flagged);
$auth->set('user_id', 42);     // writes the cookie, or silently no-ops
```

## In tests

If your code-under-test only calls `get/set/has/remove/destroy` and
asserts on its own behaviour (not on the adapter's behaviour), inject
`NullAdapter` to keep the test deterministic:

```php
use InitPHP\Auth\NullAdapter;

public function testUserServiceLogsOutEvenWhenNoAuthState(): void
{
    $service = new UserService(new NullAdapter());
    $service->logout();   // does not blow up; nothing to clear, but that's fine
}
```

For tests where the adapter's behaviour **does** matter (you want to
assert that a key was written, say), use
[`RecordingAdapter`](Testing.md#segmentcustom-with-a-recordingadapter)
or the [`InMemoryCookieWriter`](Cookie-Writer.md#inmemorycookiewriter)
pattern instead.

## v1 → v2 behaviour change

In v1, `NullAdapter::has()` returned `true`, which combined with
`get()` always returning `$default` produced the inconsistent pair
`has(x) === true && get(x) === null`. v2 makes `has()` honest: nothing
is ever present in a Null Object store.

If you relied on the old behaviour to satisfy a guard like
`if (!$adapter->has('user'))`, the guard will now fire correctly under
`NullAdapter`. In most cases that is the bug fix you wanted; if it is
not, the call site should not have been using `NullAdapter` to begin
with.

See [Migration Guide → NullAdapter](Migration-Guide.md#nulladapterhas-returns-false)
for the full BC note.

## Common mistakes

- **Reaching for `NullAdapter` when you actually want a mock that
  records calls.** `NullAdapter` is *amnesiac* — it cannot tell you
  what was written. For that, use `RecordingAdapter` (a small test
  fixture in the package's own tests directory) or PHPUnit's
  `createMock(AdapterInterface::class)`.
- **Using `NullAdapter` in production without a `final` guard.** If
  your service must have state, do not silently swallow the
  observation that the configured adapter dropped it. Throw, log, or
  fail closed.

## Where to go next

- [Testing](Testing.md) — fuller test patterns, including
  `InMemoryCookieWriter` and `RecordingAdapter`.
- [Adapter Interface](Adapter-Interface.md) — the contract this class
  satisfies.
- [API Reference](API-Reference.md#nulladapter) — full method signatures.
