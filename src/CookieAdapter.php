<?php
/**
 * CookieAdapter.php
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

class CookieAdapter extends AbstractAdapter
{
    protected string $name;

    protected ParameterBag $cookie;

    protected string $salt;

    protected array $options = [
        'expires'   => null,
        'path'      => '/',
        'secure'    => false, // [true|false]
        'httponly'  => true, // [true|false]
        'samesite'  => 'None', // [None|Lax|Strict]
    ];

    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;

        if(!isset($options['salt']) || !\is_string($options['salt']) || \strlen(\trim($options['salt'])) < 8){
            throw new \InvalidArgumentException('A "salt" with a minimum of 8 characters must be defined.');
        }
        $this->salt = $options['salt'];
        unset($options['salt']);

        if(!isset($options['expires'])){
            $options['expires'] = \time() + 86400;
        }
        $this->options = \array_merge($this->options, $options);

        $this->cookie = new ParameterBag(($this->decoder() ?? []), [
            'isMulti'   => false
        ]);
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
        $this->save();
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
        $this->save();
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
        foreach ($key as $name) {
            $this->getBag()->remove($name);
        }
        $this->save();
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function destroy(): bool
    {
        $this->getBag()->close();
        unset($this->cookie);
        \setcookie($this->name, '', [
            'expires'   => (\time() - 86400)
        ]);
        if (isset($_COOKIE[$this->name])) {
            unset($_COOKIE[$this->name]);
            return true;
        }
        return false;
    }

    private function getBag(): ParameterBag
    {
        if (isset($this->cookie)) {
            return $this->cookie;
        }
        throw new \RuntimeException('The cookie has been destroyed or not created at all.');
    }

    private function save(): bool
    {
        $data = $this->getBag()->all();
        $value = $this->encoder($data);
        return \setcookie($this->name, $value, $this->options);
    }

    private function decoder(): array
    {
        if(!isset($_COOKIE[$this->name])){
            return [];
        }
        if(($cookie = \base64_decode($_COOKIE[$this->name])) === FALSE){
            return [];
        }
        if(($cookie = \unserialize($cookie)) === FALSE){
            return [];
        }
        if (!isset($cookie['data']) || !\is_array($cookie['data']) || empty($cookie['hash'])) {
            return [];
        }
        if ($cookie['hash'] != $this->hash_generator($cookie['data'])) {
            return [];
        }
        return $cookie['data'];
    }

    private function encoder(array $data): string
    {
        $cookie = [
            'data'  => $data,
            'hash'  => $this->hash_generator($data),
        ];
        return \base64_encode(\serialize($cookie));
    }

    private function hash_generator($data): string
    {
        $data = \sha1((\serialize($data) . \strrev($this->salt)));
        $data = $this->salt . $data;
        return \md5($data);
    }

}
