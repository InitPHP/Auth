# Installation

## Requirements

| | |
| --- | --- |
| PHP | `^8.2` (tested through 8.5) |
| Extensions | `ext-json`, `ext-hash` (bundled with default PHP builds) |
| Runtime dependencies | [`initphp/parameterbag`](https://github.com/InitPHP/ParameterBag) `^2.0` |

Development dependencies (PHPUnit, PHPStan, PHP-CS-Fixer) live under
`require-dev` and are not installed when you depend on
`initphp/auth` from another project.

## Install via Composer

```bash
composer require initphp/auth
```

The classes are autoloaded under the `InitPHP\Auth` namespace via PSR-4:

```json
{
    "autoload": {
        "psr-4": {
            "InitPHP\\Auth\\": "src/"
        }
    }
}
```

Cookie-related types live in a sub-namespace:

```
InitPHP\Auth\Cookie\CookieWriterInterface
InitPHP\Auth\Cookie\NativeCookieWriter
InitPHP\Auth\Cookie\InMemoryCookieWriter
```

## Verifying the install

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Auth\Permission;

$perm = new Permission(['Editor']);
var_dump($perm->is('editor'));   // bool(true)
```

If the autoloader cannot locate the class, double-check that you ran
`composer install` (or `composer dump-autoload`) and that your script
requires `vendor/autoload.php` before instantiating the type.

## Supported PHP versions in CI

Every PR runs the test matrix against:

- PHP 8.2
- PHP 8.3
- PHP 8.4
- PHP 8.5

The static analysis (PHPStan level 8) and code-style (PHP-CS-Fixer) jobs
run against the highest supported version.

If you discover a regression on a supported version, please
[file an issue](https://github.com/InitPHP/Auth/issues/new) and include
the PHP version reported by `php -v`.

## Optional: configure the session save path for tests

If you run the test suite against a vanilla PHP CLI binary, the SAPI does
not seed `session.save_path` and `session_start()` will silently bail.
The package ships its own `phpunit.xml.dist` that sets a working path,
but if you wire up a custom one make sure to mirror these directives:

```xml
<php>
    <ini name="session.save_handler" value="files"/>
    <ini name="session.save_path"    value="/tmp"/>
    <ini name="output_buffering"     value="4096"/>
</php>
```

## Next steps

- Walk through the everyday API in [Quick Start](Quick-Start.md).
- See the full list of configuration knobs in [Configuration](Configuration.md).
- Upgrading from v1? Read the [Migration Guide](Migration-Guide.md).
