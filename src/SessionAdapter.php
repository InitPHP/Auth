<?php
/**
 * SessionAdapter.php
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

use InitPHP\ParameterBag\ParameterBag;

class SessionAdapter extends AbstractAdapter
{

    protected string $name;

    protected ParameterBag $session;

    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        if (\session_status() !== \PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Sessions must be started.');
        }
        $sessions = $_SESSION[$this->name] ?? [];
        $this->session = new ParameterBag($sessions, [
            'isMulti'   => false
        ]);
    }

    public function __call($name, $arguments)
    {
        return $this->getBag()->{$name}(...$arguments);
    }

    /**
     * @inheritDoc
     */
    public function get(string $key, $default = null)
    {
        return $this->getBag()->get($key, $default);
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, $value): self
    {
        $this->getBag()->set($key, $value);
        $_SESSION[$this->name] = $this->getBag()->all();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function collective(array $data): self
    {
        foreach ($data as $key => $value) {
            $this->getBag()->set($key, $value);
        }
        $_SESSION[$this->name] = $this->getBag()->all();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return $this->getBag()->has($key);
    }

    /**
     * @inheritDoc
     */
    public function remove(string ...$key): self
    {
        foreach ($key as $value) {
            $this->getBag()->remove($value);
        }
        $_SESSION[$this->name] = $this->getBag()->all();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function destroy(): bool
    {
        $this->getBag()->close();
        if(isset($_SESSION[$this->name])){
            unset($_SESSION[$this->name]);
            return true;
        }
        return false;
    }

    private function getBag(): ParameterBag
    {
        if(isset($this->session)){
            return $this->session;
        }
        throw new \RuntimeException('Sessions were destroyed or not created at all.');
    }

}
