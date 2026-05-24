# NullAdapter

`InitPHP\Auth\NullAdapter` is a [Null Object](https://en.wikipedia.org/wiki/Null_object_pattern)
implementation of `AdapterInterface`. Every operation succeeds and
stores nothing.

## Goal

You have code that expects an `AdapterInterface` but you do not want to
materialise a real backing store — typically in tests, CLI scripts, or
behind a feature flag.

## Working example

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use InitPHP\Auth\NullAdapter;

$adapter = new NullAdapter();

$adapter->set('user_id', 42);
var_export($adapter->get('user_id'));  // NULL
var_export($adapter->has('user_id'));  // false
var_export($adapter->destroy());       // true
```

Expected output:

```
NULL
false
true
```

## Behaviour summary

| Method | Returns |
| --- | --- |
| `get($key, $default = null)` | `$default` (always). |
| `set($key, $value)` | `$this` (no-op). |
| `collective($data)` | `$this` (no-op). |
| `has($key)` | `false` (always). |
| `remove(...$keys)` | `$this` (no-op). |
| `destroy()` | `true`. |

## When to use it

- **Unit tests** for code that depends on an adapter but should not
  exercise session / cookie machinery.
- **CLI scripts** where you want the code path to run without a real
  user.
- **Feature flags** — wrap the real adapter behind a check and fall
  back to `NullAdapter` when the feature is off.

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
