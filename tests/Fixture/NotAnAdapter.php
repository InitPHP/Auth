<?php

declare(strict_types=1);

namespace InitPHP\Auth\Tests\Fixture;

/**
 * Plain class that does NOT extend AbstractAdapter — used to assert
 * that {@see \InitPHP\Auth\Segment} rejects unrelated classes.
 */
final class NotAnAdapter
{
}
