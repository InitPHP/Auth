<?php

declare(strict_types=1);

namespace InitPHP\Auth;

use InvalidArgumentException;
use ReflectionClass;

/**
 * Facade in front of a single {@see AdapterInterface}.
 *
 * Exists for two reasons:
 *  - centralises adapter resolution (constant -> SessionAdapter / CookieAdapter,
 *    class name -> reflection-instantiated) so callers do not have to
 *    repeat the wiring;
 *  - implements {@see AdapterInterface} itself so the facade is a drop-
 *    in replacement wherever the interface is expected.
 *
 * For one-off adapter methods that are not part of the interface (e.g.
 * a custom adapter exposes a `refreshToken()` call), reach the
 * underlying instance via {@see self::adapter()} or rely on the
 * {@see self::__call()} delegation.
 */
class Segment implements AdapterInterface
{
    public const ADAPTER_SESSION = 0;
    public const ADAPTER_COOKIE = 1;

    protected AdapterInterface $adapter;

    /**
     * @param int|string           $adapter One of the ADAPTER_* constants
     *                                      or a FQCN that extends
     *                                      {@see AbstractAdapter}.
     * @param array<string, mixed> $options Forwarded to the adapter
     *                                      constructor.
     *
     * @throws InvalidArgumentException When $adapter cannot be resolved.
     */
    public function __construct(string $name, $adapter = self::ADAPTER_SESSION, array $options = [])
    {
        $this->adapter = $this->resolveAdapter($name, $adapter, $options);
    }

    /**
     * Generic factory mirroring the constructor — kept for v1 BC. Prefer
     * the typed {@see self::session()} / {@see self::cookie()} /
     * {@see self::custom()} factories in new code.
     *
     * @param int|string           $adapter
     * @param array<string, mixed> $options
     */
    public static function create(string $name, $adapter = self::ADAPTER_SESSION, array $options = []): self
    {
        return new self($name, $adapter, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function session(string $name, array $options = []): self
    {
        return new self($name, self::ADAPTER_SESSION, $options);
    }

    /**
     * @param array<string, mixed> $options Must contain `salt`. See
     *                                      {@see CookieAdapter} for the
     *                                      full options matrix.
     */
    public static function cookie(string $name, array $options = []): self
    {
        return new self($name, self::ADAPTER_COOKIE, $options);
    }

    /**
     * @param class-string<AbstractAdapter> $adapterClass
     * @param array<string, mixed>          $options
     */
    public static function custom(string $name, string $adapterClass, array $options = []): self
    {
        return new self($name, $adapterClass, $options);
    }

    /**
     * Escape hatch for code that needs the concrete adapter (e.g. to
     * call an implementation-specific method that is not part of
     * {@see AdapterInterface}).
     */
    public function adapter(): AdapterInterface
    {
        return $this->adapter;
    }

    public function get(string $key, $default = null)
    {
        return $this->adapter->get($key, $default);
    }

    public function set(string $key, $value): AdapterInterface
    {
        $this->adapter->set($key, $value);

        return $this;
    }

    public function collective(array $data): AdapterInterface
    {
        $this->adapter->collective($data);

        return $this;
    }

    public function has(string $key): bool
    {
        return $this->adapter->has($key);
    }

    public function remove(string ...$key): AdapterInterface
    {
        $this->adapter->remove(...$key);

        return $this;
    }

    public function destroy(): bool
    {
        return $this->adapter->destroy();
    }

    /**
     * Forward calls that the explicit proxy methods do not cover (e.g.
     * an extension method on a custom adapter). The call is delegated
     * verbatim, so a method that does not exist on the adapter raises
     * the standard PHP "Call to undefined method" error.
     *
     * @param array<int, mixed> $arguments
     *
     * @return mixed
     *
     * @throws \Error When the adapter does not expose $name.
     */
    public function __call(string $name, array $arguments)
    {
        return $this->adapter->{$name}(...$arguments);
    }

    /**
     * @param int|string           $adapter
     * @param array<string, mixed> $options
     */
    private function resolveAdapter(string $name, $adapter, array $options): AdapterInterface
    {
        if (\is_int($adapter)) {
            switch ($adapter) {
                case self::ADAPTER_SESSION:
                    return new SessionAdapter($name, $options);
                case self::ADAPTER_COOKIE:
                    return new CookieAdapter($name, $options);
                default:
                    throw new InvalidArgumentException(\sprintf(
                        'Unknown adapter constant: %d. Expected ADAPTER_SESSION (%d) or ADAPTER_COOKIE (%d).',
                        $adapter,
                        self::ADAPTER_SESSION,
                        self::ADAPTER_COOKIE
                    ));
            }
        }

        if (!\is_string($adapter)) {
            throw new InvalidArgumentException(
                '$adapter must be one of the ADAPTER_* constants or a class name that extends ' . AbstractAdapter::class . '.'
            );
        }

        if (!\class_exists($adapter)) {
            throw new InvalidArgumentException(\sprintf('Adapter class "%s" does not exist.', $adapter));
        }

        $reflection = new ReflectionClass($adapter);
        if (!$reflection->isSubclassOf(AbstractAdapter::class)) {
            throw new InvalidArgumentException(\sprintf(
                'Adapter class "%s" must extend %s.',
                $adapter,
                AbstractAdapter::class
            ));
        }

        /** @var AdapterInterface $instance */
        $instance = $reflection->newInstanceArgs([$name, $options]);

        return $instance;
    }
}
