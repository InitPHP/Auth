<?php

declare(strict_types=1);

namespace InitPHP\Auth\Cookie;

/**
 * Test double — captures every send() call instead of emitting headers.
 *
 * Tests can assert on the recorded calls to verify that CookieAdapter
 * built the right name/value/options triple (in particular: that destroy()
 * reuses the original path/domain so the browser actually drops the
 * cookie).
 */
final class InMemoryCookieWriter implements CookieWriterInterface
{
    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    private array $calls = [];

    /**
     * Controls the boolean returned by {@see self::send()}. Useful for
     * simulating "headers already sent" failures.
     */
    private bool $returnValue = true;

    /**
     * @param array<string, mixed> $options
     */
    public function send(string $name, string $value, array $options): bool
    {
        $this->calls[] = [
            'name'    => $name,
            'value'   => $value,
            'options' => $options,
        ];

        return $this->returnValue;
    }

    /**
     * @return list<array{name: string, value: string, options: array<string, mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return array{name: string, value: string, options: array<string, mixed>}|null
     */
    public function lastCall(): ?array
    {
        return $this->calls === [] ? null : $this->calls[\count($this->calls) - 1];
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    public function returnValue(bool $value): void
    {
        $this->returnValue = $value;
    }
}
