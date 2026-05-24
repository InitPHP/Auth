<?php

declare(strict_types=1);

namespace InitPHP\Auth\Cookie;

/**
 * Default writer — delegates straight to PHP's native setcookie().
 *
 * This is the implementation CookieAdapter uses when no other writer is
 * supplied. It exists as a separate class so that the static call site
 * has a single, mockable seam.
 */
final class NativeCookieWriter implements CookieWriterInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function send(string $name, string $value, array $options): bool
    {
        return \setcookie($name, $value, $options);
    }
}
