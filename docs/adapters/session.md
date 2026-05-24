# SessionAdapter

`InitPHP\Auth\SessionAdapter` stores auth state under a single key inside
`$_SESSION`. It is the default backing store for `Segment::session()`.

## Goal

Persist the logged-in user's id and role across requests using PHP's
native session machinery, without manually keying into `$_SESSION` at
every call site.

## Working example

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use InitPHP\Auth\Segment;

session_start();

$auth = Segment::session('auth');
$auth->set('user_id', 42)->set('role', 'editor');

echo $auth->get('user_id'), PHP_EOL;
var_export(isset($_SESSION['auth']));
```

Expected output:

```
42
true
```

The state lives at `$_SESSION['auth']` — `'auth'` is the segment name
passed to the factory. Multiple segments coexist happily under different
names:

```php
$auth = Segment::session('auth');
$cart = Segment::session('cart');

$auth->set('user_id', 42);
$cart->set('items', 3);

$_SESSION; // ['auth' => ['user_id' => 42], 'cart' => ['items' => 3]]
```

## Active session is mandatory

The adapter refuses to operate against an inactive session because doing
so would silently drop every subsequent write.

```php
// session_status() === PHP_SESSION_NONE
new SessionAdapter('auth');
// RuntimeException: Sessions must be started.
```

Call `session_start()` before instantiating the adapter (or the segment
factory).

## Constructor options

The second argument is forwarded straight to the internal
[`ParameterBag`](https://github.com/InitPHP/ParameterBag). The useful
knobs are:

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `isMulti` | `bool` | `false` | Enables dotted-path access (`$auth->get('user.profile.name')`). |
| `separator` | `string` | `'.'` | Path delimiter when `isMulti` is on. |
| `caseInsensitive` | `bool` | `false` | Folds every key to lower-case on storage and lookup. |

```php
$_SESSION['auth'] = ['db' => ['host' => 'localhost', 'port' => 3306]];

$auth = new SessionAdapter('auth', ['isMulti' => true]);
$auth->get('db.host');  // 'localhost'
$auth->get('db.port');  // 3306
```

## Lifecycle

| Call | Effect on `$_SESSION` |
| --- | --- |
| `set($k, $v)` | Writes `$_SESSION[$name]` with the updated bag. |
| `collective([...])` | Same, in one go (does not emit per-key writes). |
| `remove($k)` | Drops the key, then re-syncs `$_SESSION[$name]`. |
| `destroy()` | `unset($_SESSION[$name])`; returns `true` if the slot existed. |

After `destroy()`, any read or write on the adapter raises
`RuntimeException`. Create a fresh adapter if you need to start over.

## Common mistakes

- **Calling `session_destroy()` instead of `$auth->destroy()`.** The
  adapter only touches its own slot — that is the point of segmenting
  the session. `session_destroy()` would wipe every segment plus any
  unrelated state PHP holds for the user.
- **Sharing one `SessionAdapter` instance across forks/queues.** PHP
  sessions are tied to the current request. Move state through your
  job payload, not through the session bag.
- **Forgetting `session_start()` when running CLI tests.** The CLI SAPI
  does not seed `session.save_path`; supply it via `ini_set()` or a
  `phpunit.xml` `<ini>` entry.
