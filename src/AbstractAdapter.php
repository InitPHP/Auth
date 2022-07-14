<?php
/**
 * AbstractAdapter.php
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

abstract class AbstractAdapter implements AdapterInterface
{

    abstract public function __construct(string $name, array $options = []);

    /**
     * @inheritDoc
     */
    abstract public function get(string $key, $default = null);

    /**
     * @inheritDoc
     */
    abstract public function set(string $key, $value): AdapterInterface;

    /**
     * @inheritDoc
     */
    abstract public function collective(array $data): AdapterInterface;

    /**
     * @inheritDoc
     */
    abstract public function has(string $key): bool;

    /**
     * @inheritDoc
     */
    abstract public function remove(string ...$key): AdapterInterface;

    /**
     * @inheritDoc
     */
    abstract public function destroy(): bool;

}
