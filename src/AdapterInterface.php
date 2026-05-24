<?php

declare(strict_types=1);

namespace InitPHP\Auth;

/**
 * Storage contract for auth state.
 *
 * Each implementation owns one backing store (session, signed cookie,
 * database, in-memory, …). Constructors are intentionally NOT part of
 * the contract — different stores need different dependencies (a salt,
 * a PDO handle, a writer object), and forcing a single signature would
 * defeat the purpose of the abstraction.
 *
 * `set()`, `collective()` and `remove()` return `static` so calls can be
 * chained without losing the concrete type to the implementing class.
 */
interface AdapterInterface
{
    /**
     * Return the value stored under $key, or $default when absent.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Assign $value to $key in the backing store.
     *
     * @param mixed $value
     *
     * @return static
     */
    public function set(string $key, $value): self;

    /**
     * Apply every (key, value) pair from $data in one logical operation.
     * Implementations are free to commit atomically (one Set-Cookie /
     * one $_SESSION write) instead of iterating set() N times.
     *
     * @param array<string, mixed> $data
     *
     * @return static
     */
    public function collective(array $data): self;

    /**
     * Whether $key is present in the backing store. A stored null is
     * considered present.
     */
    public function has(string $key): bool;

    /**
     * Drop one or more keys. Missing keys are a no-op.
     *
     * @return static
     */
    public function remove(string ...$key): self;

    /**
     * Tear down the backing store. Behaviour after destroy() is
     * implementation-defined — most adapters will throw on subsequent
     * get/set calls.
     */
    public function destroy(): bool;
}
