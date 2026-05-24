# Getting started

## Install

```bash
composer require initphp/auth
```

Requires PHP 8.0 or later (tested up to 8.4) and the bundled
[`initphp/parameterbag`](https://github.com/InitPHP/ParameterBag) `^2.0`.

## Your first segment

A `Segment` is a named slice of auth state. Pick a backing adapter,
write some keys, read them back.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use InitPHP\Auth\Segment;

session_start();

$auth = Segment::session('auth');
$auth->set('user_id', 42)->set('role', 'editor');

echo $auth->get('user_id'), PHP_EOL;  // 42
var_export($auth->has('role'));        // true
$auth->destroy();
```

Expected output:

```
42
true
```

## Picking an adapter

| Adapter | Pick when |
| --- | --- |
| `SessionAdapter` | You already use PHP sessions and trust them to live as long as the user does. |
| `CookieAdapter` | You want a stateless server, or you want auth state to survive a session restart. The cookie is signed but **not** encrypted — do not put secrets in it. |
| Custom (implements `AdapterInterface`) | You want to keep the state in a database, Redis, JWT, or anywhere else. |
| `NullAdapter` | Tests, CLI scripts, feature flags — a drop-in that throws nothing and stores nothing. |

The factory methods on `Segment` mirror those:

```php
Segment::session('auth');
Segment::cookie('auth', ['salt' => $secret]);
Segment::custom('auth', App\Auth\DatabaseAdapter::class, [...]);
```

The legacy `Segment::create($name, $adapter, $options)` factory is also
kept for v1 callers.

## A note on permissions

The `Permission` class is a separate, dependency-free helper. It is
case-insensitive on the way in **and** on the way out, so the v1 footgun
where `new Permission(['Editor'])->is('editor')` returned `false` no
longer exists.

```php
use InitPHP\Auth\Permission;

$perm = new Permission(['Editor', 'POST_LIST']);
$perm->is('editor');     // true
$perm->is('post_list');  // true
```

## Next steps

- Walk through the [Permission API](permissions.md).
- Read the adapter you picked:
  [Session](adapters/session.md) ·
  [Cookie](adapters/cookie.md) ·
  [Custom](adapters/custom.md) ·
  [Null](adapters/null.md).
- Coming from v1? See [Upgrading from v1](upgrading-from-v1.md).
