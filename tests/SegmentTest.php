<?php

declare(strict_types=1);

namespace InitPHP\Auth\Tests;

use Error;
use InitPHP\Auth\AdapterInterface;
use InitPHP\Auth\CookieAdapter;
use InitPHP\Auth\Segment;
use InitPHP\Auth\SessionAdapter;
use InitPHP\Auth\Tests\Fixture\NotAnAdapter;
use InitPHP\Auth\Tests\Fixture\RecordingAdapter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SegmentTest extends TestCase
{
    private const VALID_SALT = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $_COOKIE = [];
    }

    public function testCustomFactoryInstantiatesUserAdapterWithForwardedOptions(): void
    {
        $segment = Segment::custom('auth', RecordingAdapter::class, ['foo' => 'bar']);

        /** @var RecordingAdapter $adapter */
        $adapter = $segment->adapter();
        self::assertInstanceOf(RecordingAdapter::class, $adapter);
        self::assertSame('auth', $adapter->constructorName);
        self::assertSame(['foo' => 'bar'], $adapter->constructorOptions);
    }

    public function testCookieFactoryReturnsCookieAdapter(): void
    {
        $segment = Segment::cookie('auth', ['salt' => self::VALID_SALT]);

        self::assertInstanceOf(CookieAdapter::class, $segment->adapter());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testSessionFactoryReturnsSessionAdapter(): void
    {
        \session_start();

        $segment = Segment::session('auth');
        self::assertInstanceOf(SessionAdapter::class, $segment->adapter());
    }

    public function testLegacyCreateWithIntegerConstantStillResolves(): void
    {
        $segment = Segment::create('auth', Segment::ADAPTER_COOKIE, ['salt' => self::VALID_SALT]);

        self::assertInstanceOf(CookieAdapter::class, $segment->adapter());
    }

    public function testLegacyConstructorWithStringClassNameStillResolves(): void
    {
        $segment = new Segment('auth', RecordingAdapter::class, ['k' => 'v']);

        self::assertInstanceOf(RecordingAdapter::class, $segment->adapter());
    }

    public function testRejectsUnknownIntegerAdapterConstant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown adapter constant/');
        new Segment('auth', 999);
    }

    public function testRejectsNonExistentAdapterClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/');
        new Segment('auth', 'App\\No\\Such\\Class');
    }

    public function testRejectsClassThatDoesNotExtendAbstractAdapter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must extend/');
        new Segment('auth', NotAnAdapter::class);
    }

    public function testRejectsAdapterArgumentThatIsNeitherIntNorString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line — exercising the runtime guard */
        new Segment('auth', 1.5);
    }

    public function testImplementsAdapterInterface(): void
    {
        $segment = Segment::custom('auth', RecordingAdapter::class);
        self::assertInstanceOf(AdapterInterface::class, $segment);
    }

    public function testGetDelegatesToAdapter(): void
    {
        $segment = Segment::custom('auth', RecordingAdapter::class);
        /** @var RecordingAdapter $adapter */
        $adapter = $segment->adapter();

        $result = $segment->get('user_id', 'fallback');

        self::assertSame('recorded:user_id', $result);
        self::assertSame([['method' => 'get', 'args' => ['user_id', 'fallback']]], $adapter->calls);
    }

    public function testSetDelegatesAndReturnsSegmentForChaining(): void
    {
        $segment = Segment::custom('auth', RecordingAdapter::class);
        /** @var RecordingAdapter $adapter */
        $adapter = $segment->adapter();

        $returned = $segment->set('user_id', 42);

        self::assertSame($segment, $returned);
        self::assertSame([['method' => 'set', 'args' => ['user_id', 42]]], $adapter->calls);
    }

    public function testCollectiveDelegatesAndReturnsSegment(): void
    {
        $segment = Segment::custom('auth', RecordingAdapter::class);
        /** @var RecordingAdapter $adapter */
        $adapter = $segment->adapter();

        // collective() on RecordingAdapter falls through to AbstractAdapter's
        // default implementation, which iterates set() per pair.
        $segment->collective(['a' => 1, 'b' => 2]);

        self::assertSame(
            [
                ['method' => 'set', 'args' => ['a', 1]],
                ['method' => 'set', 'args' => ['b', 2]],
            ],
            $adapter->calls
        );
    }

    public function testHasRemoveAndDestroyDelegate(): void
    {
        $segment = Segment::custom('auth', RecordingAdapter::class);
        /** @var RecordingAdapter $adapter */
        $adapter = $segment->adapter();

        self::assertTrue($segment->has('x'));
        self::assertSame($segment, $segment->remove('a', 'b'));
        self::assertTrue($segment->destroy());

        self::assertSame(
            [
                ['method' => 'has', 'args' => ['x']],
                ['method' => 'remove', 'args' => ['a', 'b']],
                ['method' => 'destroy', 'args' => []],
            ],
            $adapter->calls
        );
    }

    public function testMagicCallForwardsToAdapterExtensionMethods(): void
    {
        $segment = Segment::custom('auth', RecordingAdapter::class);
        /** @var RecordingAdapter $adapter */
        $adapter = $segment->adapter();

        /** @phpstan-ignore-next-line — exercising __call forwarding */
        $result = $segment->refreshToken('expired');

        self::assertSame('refreshed:expired', $result);
        self::assertSame([['method' => 'refreshToken', 'args' => ['expired']]], $adapter->calls);
    }

    public function testMagicCallSurfacesErrorWhenAdapterMethodMissing(): void
    {
        $segment = Segment::custom('auth', RecordingAdapter::class);

        $this->expectException(Error::class);
        /** @phpstan-ignore-next-line */
        $segment->totallyMadeUp();
    }
}
