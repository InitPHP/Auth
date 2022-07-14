<?php
/**
 * Permission.php
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

class Permission
{

    protected array $_perms = [];

    public function __construct(array $perms = [])
    {
        $this->_perms = $perms;
    }

    public function __call($name, $arguments)
    {
        if (\substr($name, 0, 3) == 'is_') {
            $perm = \substr($name, 3);
            return !empty($perm) && $this->is($perm);
        }
        throw new \RuntimeException('The ' . $name . ' method is not available in the ' . __CLASS__ .  ' class.');
    }

    public function __isset($name)
    {
        if(\substr($name, 0, 3) == 'is_'){
            $name = \substr($name, 3);
        }
        return $this->is($name);
    }

    public function __unset($name)
    {
        if(\substr($name, 0, 3) == 'is_'){
            $name = \substr($name, 3);
        }
        $this->remove($name);
        return null;
    }

    public function __sleep()
    {
        return ['_perms'];
    }

    public function getPermission(): array
    {
        return $this->_perms;
    }

    public function is(string ...$permission_name): bool
    {
        foreach ($permission_name as $name) {
            if(\in_array(\strtolower($name), $this->_perms, true)){
                return true;
            }
        }

        return false;
    }

    public function push(string ...$permissions): int
    {
        $res = 0;
        foreach ($permissions as $perm) {
            $lowercase = \strtolower($perm);
            if(\in_array($lowercase, $this->_perms, true)){
                continue;
            }
            ++$res;
            $this->_perms[] = $lowercase;
        }

        return $res;
    }

    public function remove(string ...$permissions): int
    {
        $res = 0;
        foreach ($permissions as $perm) {
            if (($search = \array_search(\strtolower($perm), $this->_perms, true)) === FALSE) {
                continue;
            }
            ++$res;
            unset($this->_perms[$search]);
        }

        return $res;
    }

}
