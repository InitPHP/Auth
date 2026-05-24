<?php

declare(strict_types=1);

namespace InitPHP\Auth;

use InitPHP\ParameterBag\ParameterBag;

/**
 * Stores auth state under a single key inside $_SESSION.
 *
 * The caller is responsible for starting the session before constructing
 * the adapter; the adapter refuses to operate against an inactive session
 * because doing so would silently lose every subsequent write.
 */
class SessionAdapter extends AbstractAdapter
{
    protected string $name;

    protected ParameterBag $session;

    /**
     * @param array<string, mixed> $options Forwarded to the internal
     *                                      {@see ParameterBag}. Useful
     *                                      knobs: `isMulti` (dotted
     *                                      paths), `separator`,
     *                                      `caseInsensitive`. Defaults
     *                                      to flat mode (`isMulti => false`).
     *
     * @throws \RuntimeException When the PHP session is not active.
     */
    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        if (\session_status() !== \PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Sessions must be started.');
        }
        /** @var array<string, mixed> $sessions */
        $sessions = $_SESSION[$this->name] ?? [];
        $this->session = new ParameterBag($sessions, \array_merge(['isMulti' => false], $options));
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     *
     * @throws \RuntimeException When the session has been destroyed.
     */
    public function get(string $key, $default = null)
    {
        return $this->getBag()->get($key, $default);
    }

    /**
     * @param mixed $value
     *
     * @throws \RuntimeException When the session has been destroyed.
     */
    public function set(string $key, $value): AdapterInterface
    {
        $this->getBag()->set($key, $value);
        $this->syncSession();

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the session has been destroyed.
     */
    public function collective(array $data): AdapterInterface
    {
        foreach ($data as $key => $value) {
            $this->getBag()->set((string) $key, $value);
        }
        $this->syncSession();

        return $this;
    }

    /**
     * @throws \RuntimeException When the session has been destroyed.
     */
    public function has(string $key): bool
    {
        return $this->getBag()->has($key);
    }

    /**
     * @throws \RuntimeException When the session has been destroyed.
     */
    public function remove(string ...$key): AdapterInterface
    {
        foreach ($key as $value) {
            $this->getBag()->remove($value);
        }
        $this->syncSession();

        return $this;
    }

    /**
     * Drops the $_SESSION slot held by this segment. Returns true when
     * the slot existed, false otherwise. After destroy() any further
     * get/set/has/remove/collective call raises {@see \RuntimeException}.
     */
    public function destroy(): bool
    {
        $this->getBag()->close();
        unset($this->session);
        if (isset($_SESSION[$this->name])) {
            unset($_SESSION[$this->name]);

            return true;
        }

        return false;
    }

    private function getBag(): ParameterBag
    {
        if (isset($this->session)) {
            return $this->session;
        }
        throw new \RuntimeException('Sessions were destroyed or not created at all.');
    }

    private function syncSession(): void
    {
        $_SESSION[$this->name] = $this->getBag()->all();
    }
}
