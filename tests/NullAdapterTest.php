<?php

declare(strict_types=1);

namespace InitPHP\Auth\Tests;

use InitPHP\Auth\AdapterInterface;
use InitPHP\Auth\NullAdapter;
use PHPUnit\Framework\TestCase;

final class NullAdapterTest extends TestCase
{
    public function testGetAlwaysReturnsDefault(): void
    {
        $adapter = new NullAdapter();

        self::assertNull($adapter->get('missing'));
        self::assertSame('fallback', $adapter->get('missing', 'fallback'));
    }

    /**
     * Regression for the v1 inconsistency where has() returned true while
     * get() returned the default. v2 makes has() honest: nothing is ever
     * present in a Null Object store.
     */
    public function testHasAlwaysReturnsFalse(): void
    {
        $adapter = new NullAdapter();

        self::assertFalse($adapter->has('anything'));
    }

    public function testSetIsANoOpButReturnsAdapter(): void
    {
        $adapter = new NullAdapter();

        self::assertSame($adapter, $adapter->set('user_id', 42));
        self::assertFalse($adapter->has('user_id'));
        self::assertNull($adapter->get('user_id'));
    }

    public function testCollectiveIsANoOpButReturnsAdapter(): void
    {
        $adapter = new NullAdapter();

        self::assertSame($adapter, $adapter->collective(['a' => 1, 'b' => 2]));
        self::assertFalse($adapter->has('a'));
    }

    public function testRemoveIsANoOpButReturnsAdapter(): void
    {
        $adapter = new NullAdapter();

        self::assertSame($adapter, $adapter->remove('a', 'b'));
    }

    public function testDestroyReturnsTrue(): void
    {
        $adapter = new NullAdapter();

        self::assertTrue($adapter->destroy());
    }

    public function testImplementsAdapterInterface(): void
    {
        self::assertInstanceOf(AdapterInterface::class, new NullAdapter());
    }
}
