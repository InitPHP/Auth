<?php
/**
 * Segment.php
 *
 * This file is part of Auth.
 *
 * @author     Muhammet ŞAFAK <info@muhammetsafak.com.tr>
 * @copyright  Copyright © 2022 Muhammet ŞAFAK
 * @license    ./LICENSE  MIT
 * @version    1.0
 * @link       https://www.muhammetsafak.com.tr
 */

declare(strict_types=1);

namespace InitPHP\Auth;

/**
 * @mixin AbstractAdapter
 */
class Segment
{
    public const ADAPTER_SESSION = 0;
    public const ADAPTER_COOKIE = 1;

    /** @var AdapterInterface */
    protected AdapterInterface $adapter;

    /**
     * @param string $name
     * @param int|string $adapter
     * @param array $options
     */
    public function __construct(string $name, $adapter = self::ADAPTER_SESSION, array $options = [])
    {
        if (!\is_int($adapter) && !\is_string($adapter)) {
            throw new \InvalidArgumentException('$adapter can be a string or an integer.');
        }
        $this->_initialize($name, $adapter, $options);
    }

    public function __call($name, $arguments)
    {
        return $this->adapter->{$name}(...$arguments);
    }

    public static function create(string $name, $adapter = self::ADAPTER_SESSION, array $options = []): Segment
    {
        return new self($name, $adapter, $options);
    }

    private function _initialize(string $name, $adapter, array $options)
    {
        switch ($adapter) {
            case self::ADAPTER_SESSION:
                $this->adapter = new SessionAdapter($name, $options);
                return;
            case self::ADAPTER_COOKIE:
                $this->adapter = new CookieAdapter($name, $options);
                return;
            default:
                break;
        }
        if (!\is_string($adapter) || !\class_exists($adapter)) {
            throw new \InvalidArgumentException('$adapter can simply be a class that extends the AbstractAdapter class.');
        }
        $reflection = new \ReflectionClass($adapter);
        if (!$reflection->isSubclassOf(AbstractAdapter::class)) {
            throw new \InvalidArgumentException('$adapter can simply be a class that extends the AbstractAdapter class.');
        }
        /** @var AdapterInterface $adapter */
        $adapter = $reflection->newInstanceArgs([$name, $options]);
        $this->adapter = $adapter;
    }

}
