<?php

declare(strict_types=1);

namespace InitPHP\Auth;

use BadMethodCallException;

/**
 * Small case-insensitive permission set.
 *
 * Permissions are normalized to lower-case on the way in and compared
 * case-insensitively, so `new Permission(['Editor'])->is('editor')`
 * matches. The internal list is always a 0-indexed
 * {@link https://phpstan.org/writing-php-code/phpdoc-types#list `list<string>`}
 * — `remove()` reindexes after `unset()` to keep that invariant.
 *
 * Magic `is_*` accessors (`$perm->is_admin`, `isset($perm->is_admin)`,
 * `unset($perm->is_admin)`) are wired through {@see self::__call()},
 * {@see self::__isset()} and {@see self::__unset()} for convenience in
 * templates; the explicit {@see self::is()}, {@see self::push()} and
 * {@see self::remove()} methods are preferred in code that has access
 * to an IDE or a static analyser.
 */
class Permission
{
    /** @var list<string> */
    protected array $permissions = [];

    /**
     * @param array<int, string> $permissions Values that are not strings
     *                                        are silently skipped.
     *                                        Duplicates after normalization
     *                                        are dropped.
     */
    public function __construct(array $permissions = [])
    {
        foreach ($permissions as $perm) {
            if (!\is_string($perm)) {
                continue;
            }
            $normalized = $this->normalize($perm);
            if (\in_array($normalized, $this->permissions, true)) {
                continue;
            }
            $this->permissions[] = $normalized;
        }
    }

    /**
     * Magic dispatch for `is_*` accessors. `$perm->is_admin()` becomes
     * `$perm->is('admin')`. Any other method name raises
     * {@see BadMethodCallException}.
     *
     * @param array<int, mixed> $arguments Ignored — the accessor takes no
     *                                     parameters.
     *
     * @throws BadMethodCallException When $name does not start with `is_`.
     */
    public function __call(string $name, array $arguments): bool
    {
        if (\strncmp($name, 'is_', 3) === 0) {
            $permission = \substr($name, 3);

            return $permission !== '' && $this->is($permission);
        }

        throw new BadMethodCallException(\sprintf(
            'Method %s::%s() does not exist.',
            static::class,
            $name
        ));
    }

    /**
     * Magic dispatch for `isset($perm->some_role)` and
     * `isset($perm->is_some_role)`.
     */
    public function __isset(string $name): bool
    {
        if (\strncmp($name, 'is_', 3) === 0) {
            $name = \substr($name, 3);
        }

        return $name !== '' && $this->is($name);
    }

    /**
     * Magic dispatch for `unset($perm->some_role)` /
     * `unset($perm->is_some_role)`.
     */
    public function __unset(string $name): void
    {
        if (\strncmp($name, 'is_', 3) === 0) {
            $name = \substr($name, 3);
        }
        if ($name !== '') {
            $this->remove($name);
        }
    }

    /**
     * Only the permission list is serialized; anything else is implementation
     * detail that should not be persisted across requests.
     *
     * @return array<int, string>
     */
    public function __sleep(): array
    {
        return ['permissions'];
    }

    /**
     * @return list<string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * @deprecated since 2.0 — use {@see self::getPermissions()}. Kept
     *             as a v1 BC shim and removed in v3.
     *
     * @return list<string>
     */
    public function getPermission(): array
    {
        return $this->permissions;
    }

    /**
     * True when any of the supplied names is present in the set.
     * Comparison is case-insensitive.
     */
    public function is(string ...$permission_name): bool
    {
        foreach ($permission_name as $name) {
            if (\in_array($this->normalize($name), $this->permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add one or more permissions. Returns the count of names that were
     * actually inserted (names already present are skipped).
     */
    public function push(string ...$permissions): int
    {
        $added = 0;
        foreach ($permissions as $perm) {
            $normalized = $this->normalize($perm);
            if (\in_array($normalized, $this->permissions, true)) {
                continue;
            }
            $this->permissions[] = $normalized;
            ++$added;
        }

        return $added;
    }

    /**
     * Remove one or more permissions. Returns the count of names that
     * were actually removed. The internal list is reindexed so the
     * `list<string>` invariant holds.
     */
    public function remove(string ...$permissions): int
    {
        $removed = 0;
        foreach ($permissions as $perm) {
            $search = \array_search($this->normalize($perm), $this->permissions, true);
            if ($search === false) {
                continue;
            }
            unset($this->permissions[$search]);
            ++$removed;
        }
        if ($removed > 0) {
            $this->permissions = \array_values($this->permissions);
        }

        return $removed;
    }

    private function normalize(string $name): string
    {
        return \strtolower(\trim($name));
    }
}
