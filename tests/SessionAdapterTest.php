<?php

declare(strict_types=1);

namespace InitPHP\Auth\Tests;

use InitPHP\Auth\SessionAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each test runs in its own process so that session state is fully
 * isolated: PHP keeps a per-process session_status() flag, and the
 * adapter's "requires active session" guard cannot otherwise be
 * exercised once any earlier test has issued session_start().
 *
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
final class SessionAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION = [];
    }

    public function testConstructorRefusesToOperateWithoutActiveSession(): void
    {
        // setUp() always starts a session for this suite; close it back
        // down so the adapter sees PHP_SESSION_NONE.
        \session_write_close();
        self::assertSame(\PHP_SESSION_NONE, \session_status());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sessions must be started.');
        new SessionAdapter('auth');
    }

    public function testGetReturnsDefaultForAbsentKey(): void
    {
        $adapter = new SessionAdapter('auth');

        self::assertNull($adapter->get('missing'));
        self::assertSame('fallback', $adapter->get('missing', 'fallback'));
    }

    public function testSetPersistsValueToSessionSuperglobal(): void
    {
        $adapter = new SessionAdapter('auth');

        $adapter->set('user_id', 42);

        self::assertSame(42, $adapter->get('user_id'));
        self::assertSame(['user_id' => 42], $_SESSION['auth']);
    }

    public function testSetReturnsAdapterForChaining(): void
    {
        $adapter = new SessionAdapter('auth');

        self::assertSame($adapter, $adapter->set('user_id', 1));
    }

    public function testCollectivePersistsAllPairsAndSynchronizesSuperglobalOnce(): void
    {
        $adapter = new SessionAdapter('auth');

        $adapter->collective(['user_id' => 7, 'role' => 'editor']);

        self::assertSame(['user_id' => 7, 'role' => 'editor'], $_SESSION['auth']);
    }

    public function testHasReflectsTheCurrentState(): void
    {
        $adapter = new SessionAdapter('auth');

        self::assertFalse($adapter->has('user_id'));
        $adapter->set('user_id', 1);
        self::assertTrue($adapter->has('user_id'));
    }

    public function testRemoveDropsKeyAndSynchronizesSuperglobal(): void
    {
        $adapter = new SessionAdapter('auth');
        $adapter->collective(['user_id' => 1, 'role' => 'admin']);

        $adapter->remove('user_id');

        self::assertFalse($adapter->has('user_id'));
        self::assertSame(['role' => 'admin'], $_SESSION['auth']);
    }

    public function testRemoveAcceptsMultipleKeys(): void
    {
        $adapter = new SessionAdapter('auth');
        $adapter->collective(['a' => 1, 'b' => 2, 'c' => 3]);

        $adapter->remove('a', 'c');

        self::assertSame(['b' => 2], $_SESSION['auth']);
    }

    public function testDestroyClearsSessionSlotAndReturnsTrue(): void
    {
        $adapter = new SessionAdapter('auth');
        $adapter->set('user_id', 1);

        self::assertTrue($adapter->destroy());
        self::assertArrayNotHasKey('auth', $_SESSION);
    }

    public function testDestroyReturnsFalseWhenSlotWasNeverWritten(): void
    {
        $adapter = new SessionAdapter('auth');

        self::assertFalse($adapter->destroy());
    }

    public function testGetAfterDestroyRaisesRuntimeException(): void
    {
        $adapter = new SessionAdapter('auth');
        $adapter->destroy();

        $this->expectException(RuntimeException::class);
        $adapter->get('user_id');
    }

    public function testSetAfterDestroyRaisesRuntimeException(): void
    {
        $adapter = new SessionAdapter('auth');
        $adapter->destroy();

        $this->expectException(RuntimeException::class);
        $adapter->set('user_id', 1);
    }

    public function testReadsExistingSessionDataOnInstantiation(): void
    {
        $_SESSION['auth'] = ['user_id' => 99, 'role' => 'admin'];

        $adapter = new SessionAdapter('auth');

        self::assertSame(99, $adapter->get('user_id'));
        self::assertSame('admin', $adapter->get('role'));
    }

    public function testConstructorOptionsAreForwardedToParameterBag(): void
    {
        $_SESSION['auth'] = ['db' => ['host' => 'localhost', 'port' => 3306]];

        $adapter = new SessionAdapter('auth', ['isMulti' => true]);

        // With isMulti=true, ParameterBag walks dotted paths.
        self::assertSame('localhost', $adapter->get('db.host'));
        self::assertSame(3306, $adapter->get('db.port'));
    }

    public function testDifferentNamesCohabitateInTheSameSession(): void
    {
        $cart = new SessionAdapter('cart');
        $auth = new SessionAdapter('auth');

        $cart->set('items', 3);
        $auth->set('user_id', 1);

        self::assertSame(3, $cart->get('items'));
        self::assertSame(1, $auth->get('user_id'));
        self::assertNull($auth->get('items'));
    }
}
