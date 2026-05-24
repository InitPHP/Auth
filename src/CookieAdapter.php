<?php

declare(strict_types=1);

namespace InitPHP\Auth;

use InitPHP\Auth\Cookie\CookieWriterInterface;
use InitPHP\Auth\Cookie\NativeCookieWriter;
use InitPHP\ParameterBag\ParameterBag;
use JsonException;

/**
 * Stores auth state in a signed cookie.
 *
 * The cookie value is `base64url(json) . hmac_sha256(json, salt)`. Signing
 * happens with a constant-time HMAC and is verified BEFORE the JSON
 * payload is parsed, so a tampered cookie is dropped on the floor without
 * any deserialisation taking place.
 *
 * The encoder no longer uses PHP serialize(); upgrading from v1 cookies
 * is therefore a hard cut — old cookies become unreadable and clients
 * will be issued fresh ones on next write.
 */
class CookieAdapter extends AbstractAdapter
{
    /**
     * Minimum salt length, in bytes. SHA-256 produces 32-byte digests, so
     * a key that is at least as long denies an attacker any structural
     * shortcut. Use `bin2hex(random_bytes(32))` to generate one.
     */
    public const MIN_SALT_LENGTH = 32;

    protected string $name;

    protected ParameterBag $cookie;

    protected string $salt;

    /**
     * Default cookie attributes. Defaults are deliberately strict:
     *  - `secure=true` so cookies are never sent over plain HTTP.
     *  - `samesite=Lax` because modern browsers reject `None` unless
     *    `Secure` is also set, and `Strict` breaks ordinary navigations.
     *
     * @var array<string, mixed>
     */
    protected array $options = [
        'expires'   => null,
        'path'      => '/',
        'domain'    => '',
        'secure'    => true,
        'httponly'  => true,
        'samesite'  => 'Lax',
    ];

    private CookieWriterInterface $writer;

    /**
     * @param array<string, mixed>       $options Cookie attributes plus a
     *                                            required `salt` (see
     *                                            {@see self::MIN_SALT_LENGTH}).
     * @param CookieWriterInterface|null $writer  Defaults to
     *                                            {@see NativeCookieWriter}.
     *                                            Inject {@see InMemoryCookieWriter}
     *                                            from tests.
     */
    public function __construct(string $name, array $options = [], ?CookieWriterInterface $writer = null)
    {
        $this->name = $name;
        $this->writer = $writer ?? new NativeCookieWriter();

        if (!isset($options['salt']) || !\is_string($options['salt']) || \strlen($options['salt']) < self::MIN_SALT_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'A "salt" of at least %d bytes must be supplied. Generate one with bin2hex(random_bytes(32)).',
                self::MIN_SALT_LENGTH
            ));
        }
        $this->salt = $options['salt'];
        unset($options['salt']);

        if (!\array_key_exists('expires', $options) || $options['expires'] === null) {
            $options['expires'] = \time() + 86400;
        }
        $this->options = \array_merge($this->options, $options);

        // SameSite=None requires Secure (Chrome 80+, Firefox 69+, Safari 13.1+).
        // Reject the combination eagerly rather than silently issuing a
        // cookie the browser will drop.
        if (\strcasecmp((string) $this->options['samesite'], 'None') === 0 && $this->options['secure'] !== true) {
            throw new \InvalidArgumentException('SameSite=None requires the cookie to be marked Secure.');
        }

        $this->cookie = new ParameterBag($this->decoder(), [
            'isMulti' => false,
        ]);
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     *
     * @throws \RuntimeException When the cookie has been destroyed.
     */
    public function get(string $key, $default = null)
    {
        return $this->getBag()->get($key, $default);
    }

    /**
     * @param mixed $value
     *
     * @throws \RuntimeException When the cookie has been destroyed, or
     *                           when $value cannot be JSON-encoded.
     */
    public function set(string $key, $value): AdapterInterface
    {
        $this->getBag()->set($key, $value);
        $this->save();

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the cookie has been destroyed, or
     *                           when $data cannot be JSON-encoded.
     */
    public function collective(array $data): AdapterInterface
    {
        foreach ($data as $key => $value) {
            $this->getBag()->set((string) $key, $value);
        }
        $this->save();

        return $this;
    }

    /**
     * @throws \RuntimeException When the cookie has been destroyed.
     */
    public function has(string $key): bool
    {
        return $this->getBag()->has($key);
    }

    /**
     * @throws \RuntimeException When the cookie has been destroyed.
     */
    public function remove(string ...$key): AdapterInterface
    {
        foreach ($key as $name) {
            $this->getBag()->remove($name);
        }
        $this->save();

        return $this;
    }

    /**
     * Emits a deletion cookie that matches the original attributes and
     * returns whether the Set-Cookie header was queued successfully.
     * After destroy() any further get/set/has/remove/collective call
     * raises {@see \RuntimeException}.
     */
    public function destroy(): bool
    {
        $this->getBag()->close();
        unset($this->cookie);

        // RFC 6265: the browser only deletes a cookie when the deletion
        // header carries the same path/domain (and SameSite/Secure when
        // applicable) as the cookie being removed. Reuse the original
        // options and only override the expiry.
        $deleteOptions = $this->options;
        $deleteOptions['expires'] = \time() - 86400;
        $ok = $this->writer->send($this->name, '', $deleteOptions);

        // setcookie() does not update $_COOKIE for the current request;
        // sync the superglobal so later code in the same request does
        // not see stale data.
        if (isset($_COOKIE[$this->name])) {
            unset($_COOKIE[$this->name]);
        }

        return $ok;
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

        return $this->writer->send($this->name, $value, $this->options);
    }

    /**
     * Read and verify the cookie payload.
     *
     * Order of operations matters: the HMAC is checked BEFORE the JSON is
     * parsed so that a forged or tampered cookie never reaches the
     * decoder. Any failure (missing cookie, malformed format, bad
     * signature, invalid JSON, non-array root) yields an empty array.
     *
     * @return array<string, mixed>
     */
    private function decoder(): array
    {
        if (!isset($_COOKIE[$this->name]) || !\is_string($_COOKIE[$this->name])) {
            return [];
        }
        $raw = $_COOKIE[$this->name];
        if (\strpos($raw, '.') === false) {
            return [];
        }
        [$encodedPayload, $signature] = \explode('.', $raw, 2);

        $payload = $this->base64UrlDecode($encodedPayload);
        if ($payload === null) {
            return [];
        }
        if (!\hash_equals($this->generateSignature($payload), $signature)) {
            return [];
        }

        try {
            $data = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [];
        }

        return \is_array($data) ? $data : [];
    }

    /**
     * Build the wire format: `base64url(json).hex(hmac)`.
     *
     * @param array<string, mixed> $data
     */
    private function encoder(array $data): string
    {
        try {
            $payload = \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new \RuntimeException('Failed to encode auth payload as JSON: ' . $e->getMessage(), 0, $e);
        }

        return $this->base64UrlEncode($payload) . '.' . $this->generateSignature($payload);
    }

    private function generateSignature(string $payload): string
    {
        return \hash_hmac('sha256', $payload, $this->salt);
    }

    private function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $decoded = \base64_decode(\strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
