<?php
/**
 * NullAdapter.php
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

class NullAdapter extends AbstractAdapter
{

    public function __construct(string $name, array $options = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function get(string $key, $default = null)
    {
        return $default;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, $value): self
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function collective(array $data): self
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function remove(string ...$key): self
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function destroy(): bool
    {
        return true;
    }

}
