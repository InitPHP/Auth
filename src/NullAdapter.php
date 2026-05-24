<?php

declare(strict_types=1);

namespace InitPHP\Auth;

/**
 * Null Object adapter — accepts every operation and stores nothing.
 *
 * Useful as a default when a higher layer expects an
 * {@see AdapterInterface} but auth state should be ignored (testing,
 * feature flags, CLI scripts).
 *
 * Note (v2 behaviour change): {@see self::has()} now returns `false`.
 * In v1 it returned `true`, which combined with {@see self::get()}
 * always returning the default produced the inconsistent pair
 * `has(x) === true && get(x) === null`.
 */
final class NullAdapter extends AbstractAdapter
{
    /**
     * @param array<string, mixed> $options Accepted for signature parity
     *                                      with other adapters and
     *                                      silently ignored.
     *
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(string $name = '', array $options = [])
    {
    }

    public function get(string $key, $default = null)
    {
        return $default;
    }

    public function set(string $key, $value): AdapterInterface
    {
        return $this;
    }

    public function collective(array $data): AdapterInterface
    {
        return $this;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function remove(string ...$key): AdapterInterface
    {
        return $this;
    }

    public function destroy(): bool
    {
        return true;
    }
}
