<?php

declare(strict_types=1);

namespace InitPHP\Auth\Cookie;

/**
 * Thin abstraction over PHP's setcookie() so that CookieAdapter can be
 * exercised in unit tests without touching the response headers.
 *
 * A deletion is modelled as a normal send with an expiry in the past; the
 * caller (CookieAdapter) is responsible for building the correct options
 * array because RFC 6265 requires path/domain/SameSite to match.
 */
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
