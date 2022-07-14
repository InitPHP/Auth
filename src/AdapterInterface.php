<?php
/**
 * AdapterInterface.php
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

interface AdapterInterface
{

    public function __construct(string $name, array $options = []);

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * @param string $key
     * @param mixed $value
     * @return AdapterInterface
     */
    public function set(string $key, $value): AdapterInterface;

    /**
     * @param array $data <p>Associative array</p>
     * @return AdapterInterface
     */
    public function collective(array $data): AdapterInterface;

    /**
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * @param string ...$key
     * @return AdapterInterface
     */
    public function remove(string ...$key): AdapterInterface;

    /**
     * @return bool
     */
    public function destroy(): bool;

}
