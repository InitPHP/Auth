<?php

declare(strict_types=1);

namespace InitPHP\Auth\Tests;

use InitPHP\Auth\Cookie\InMemoryCookieWriter;
use InitPHP\Auth\CookieAdapter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CookieAdapterTest extends TestCase
{
    private const VALID_SALT = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $_COOKIE = [];
    }

    public function testRejectsMissingSalt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/salt/');
        new CookieAdapter('auth', [], new InMemoryCookieWriter());
    }

    public function testRejectsSaltShorterThanMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CookieAdapter('auth', ['salt' => 'too-short'], new InMemoryCookieWriter());
    }

    public function testRejectsNonStringSalt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CookieAdapter('auth', ['salt' => 12345], new InMemoryCookieWriter());
    }

    public function testRejectsSameSiteNoneWithoutSecure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CookieAdapter('auth', [
            'salt'     => self::VALID_SALT,
            'samesite' => 'None',
            'secure'   => false,
        ], new InMemoryCookieWriter());
    }

    public function testAcceptsSameSiteNoneWhenSecureIsTrue(): void
    {
        $writer = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', [
            'salt'     => self::VALID_SALT,
            'samesite' => 'None',
            'secure'   => true,
        ], $writer);

        $adapter->set('x', 1);

        $last = $writer->lastCall();
        self::assertNotNull($last);
        self::assertSame('None', $last['options']['samesite']);
        self::assertTrue($last['options']['secure']);
    }

    public function testAcceptsLaxWithoutSecure(): void
    {
        $writer = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', [
            'salt'     => self::VALID_SALT,
            'samesite' => 'Lax',
            'secure'   => false,
        ], $writer);

        $adapter->set('x', 1);
        $last = $writer->lastCall();
        self::assertNotNull($last);
        self::assertFalse($last['options']['secure']);
    }

    public function testCustomExpiresOptionIsRespected(): void
    {
        $writer = new InMemoryCookieWriter();
        $expires = \time() + 7200;
        $adapter = new CookieAdapter('auth', [
            'salt'    => self::VALID_SALT,
            'expires' => $expires,
        ], $writer);

        $adapter->set('x', 1);
        $last = $writer->lastCall();
        self::assertNotNull($last);
        self::assertSame($expires, $last['options']['expires']);
    }

    public function testGetReturnsDefaultForAbsentKey(): void
    {
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());

        self::assertNull($adapter->get('missing'));
        self::assertSame('fallback', $adapter->get('missing', 'fallback'));
    }

    public function testHasReflectsStoredKeys(): void
    {
        $writer = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);

        self::assertFalse($adapter->has('user_id'));
        $adapter->set('user_id', 42);
        self::assertTrue($adapter->has('user_id'));
    }

    public function testRemoveDropsKeyAndRewritesCookie(): void
    {
        $writer = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);
        $adapter->collective(['a' => 1, 'b' => 2]);

        $adapter->remove('a');

        self::assertFalse($adapter->has('a'));
        self::assertTrue($adapter->has('b'));
    }

    public function testCollectiveEmitsOneCookieForMultipleKeys(): void
    {
        $writer = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);

        $adapter->collective(['a' => 1, 'b' => 2, 'c' => 3]);

        // The default AbstractAdapter::collective iterates set() once per
        // pair, but CookieAdapter overrides it precisely so a bulk write
        // emits one Set-Cookie header rather than N.
        self::assertCount(1, $writer->calls());
    }

    public function testRoundtripsValuesViaCookie(): void
    {
        $writer = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);

        $adapter->set('user_id', 42)->set('role', 'editor');

        $last = $writer->lastCall();
        self::assertNotNull($last);
        $_COOKIE['auth'] = $last['value'];

        $second = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
        self::assertSame(42, $second->get('user_id'));
        self::assertSame('editor', $second->get('role'));
    }

    public function testRoundtripPreservesUnicodeAndSlashes(): void
    {
        $writer = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);

        $adapter->set('name', 'Müller / O\'Brien — 日本語');

        $last = $writer->lastCall();
        self::assertNotNull($last);
        $_COOKIE['auth'] = $last['value'];

        $second = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
        self::assertSame('Müller / O\'Brien — 日本語', $second->get('name'));
    }

    public function testRejectsTamperedSignature(): void
    {
        $writer = new InMemoryCookieWriter();
        (new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer))->set('user_id', 42);
        $last = $writer->lastCall();
        self::assertNotNull($last);
        $cookie = $last['value'];

        $tampered = \substr($cookie, 0, -1) . (\substr($cookie, -1) === 'a' ? 'b' : 'a');
        $_COOKIE['auth'] = $tampered;

        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
        self::assertNull($adapter->get('user_id'));
    }

    public function testRejectsCookieEncodedWithDifferentSalt(): void
    {
        $writer = new InMemoryCookieWriter();
        (new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer))->set('user_id', 42);
        $last = $writer->lastCall();
        self::assertNotNull($last);
        $_COOKIE['auth'] = $last['value'];

        // Different salt → the HMAC does not verify and decoder yields empty.
        $other = new CookieAdapter('auth', ['salt' => \str_repeat('z', 32)], new InMemoryCookieWriter());
        self::assertNull($other->get('user_id'));
    }

    /**
     * Regression for C4: destroy() must reuse the original path/domain so
     * the browser actually drops the cookie.
     */
    public function testDestroyEmitsDeletionWithOriginalAttributes(): void
    {
        $writer = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', [
            'salt'   => self::VALID_SALT,
            'path'   => '/admin',
            'domain' => 'example.com',
        ], $writer);

        $adapter->destroy();

        $last = $writer->lastCall();
        self::assertNotNull($last);
        self::assertSame('auth', $last['name']);
        self::assertSame('', $last['value']);
        self::assertSame('/admin', $last['options']['path']);
        self::assertSame('example.com', $last['options']['domain']);
        self::assertSame('Lax', $last['options']['samesite']);
        self::assertTrue($last['options']['secure']);
        self::assertLessThan(\time(), $last['options']['expires']);
    }

    public function testDestroyAlsoClearsCookieSuperglobal(): void
    {
        $_COOKIE['auth'] = 'irrelevant';
        $writer = new InMemoryCookieWriter();
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);

        $adapter->destroy();

        self::assertArrayNotHasKey('auth', $_COOKIE);
    }

    public function testGetAfterDestroyRaisesRuntimeException(): void
    {
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
        $adapter->destroy();

        $this->expectException(RuntimeException::class);
        $adapter->get('user_id');
    }

    public function testSetAfterDestroyRaisesRuntimeException(): void
    {
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
        $adapter->destroy();

        $this->expectException(RuntimeException::class);
        $adapter->set('user_id', 1);
    }

    public function testSaveSurfacesWriterFailureViaReturnValueOfDestroy(): void
    {
        $writer = new InMemoryCookieWriter();
        $writer->returnValue(false);
        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], $writer);

        self::assertFalse($adapter->destroy());
    }

    public function testGracefullyHandlesMalformedCookie(): void
    {
        $_COOKIE['auth'] = 'not-a-valid-format';

        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
        self::assertNull($adapter->get('user_id'));
    }

    public function testGracefullyHandlesLegacyV1Cookie(): void
    {
        $_COOKIE['auth'] = \base64_encode(\serialize(['data' => ['x' => 1], 'hash' => 'whatever']));

        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
        self::assertNull($adapter->get('x'));
    }

    public function testGracefullyHandlesNonStringCookieValue(): void
    {
        $_COOKIE['auth'] = ['this', 'is', 'an', 'array'];

        $adapter = new CookieAdapter('auth', ['salt' => self::VALID_SALT], new InMemoryCookieWriter());
        self::assertNull($adapter->get('user_id'));
    }
}
