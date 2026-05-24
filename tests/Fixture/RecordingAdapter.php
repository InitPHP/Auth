<?php

declare(strict_types=1);

namespace InitPHP\Auth\Tests\Fixture;

use InitPHP\Auth\AbstractAdapter;
use InitPHP\Auth\AdapterInterface;

/**
 * Captures every call routed through it so Segment delegation can be
 * asserted without spinning up a real session or cookie store.
 */
final class RecordingAdapter extends AbstractAdapter
{
    /** @var list<array{method: string, args: array<int|string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $constructorOptions = [];

    public string $constructorName = '';

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $name = '', array $options = [])
    {
        $this->constructorName = $name;
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

    /**
     * Non-interface method exercised by Segment::__call() forwarding.
     */
    public function refreshToken(string $reason): string
    {
        $this->calls[] = ['method' => 'refreshToken', 'args' => [$reason]];

        return 'refreshed:' . $reason;
    }
}
