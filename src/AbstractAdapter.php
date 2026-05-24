<?php

declare(strict_types=1);

namespace InitPHP\Auth;

/**
 * Common base for {@see AdapterInterface} implementations.
 *
 * Provides a default {@see self::collective()} that routes through
 * {@see self::set()}. Adapters that can commit atomically (CookieAdapter
 * would otherwise emit one Set-Cookie header per key) should override
 * it for efficiency.
 */
abstract class AbstractAdapter implements AdapterInterface
{
    /**
     * @param array<string, mixed> $data
     *
     * @return static
     */
    public function collective(array $data): AdapterInterface
    {
        foreach ($data as $key => $value) {
            $this->set((string) $key, $value);
        }

        return $this;
    }
}
