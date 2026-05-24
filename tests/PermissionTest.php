<?php

declare(strict_types=1);

namespace InitPHP\Auth\Tests;

use BadMethodCallException;
use InitPHP\Auth\Permission;
use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    /**
     * Regression for the v1 case-folding bug: the constructor used to
     * store permissions verbatim while is()/push()/remove() compared the
     * lower-cased needle against them.
     */
    public function testConstructorNormalizesPermissionsForCaseInsensitiveCheck(): void
    {
        $perm = new Permission(['Editor', 'POST_LIST', 'Post_Add']);

        self::assertTrue($perm->is('editor'));
        self::assertTrue($perm->is('post_list'));
        self::assertTrue($perm->is('post_add'));
        self::assertTrue($perm->is('EDITOR'));
    }

    public function testConstructorDeduplicatesNormalizedPermissions(): void
    {
        $perm = new Permission(['Editor', 'editor', 'EDITOR']);

        self::assertSame(['editor'], $perm->getPermissions());
    }

    public function testConstructorTrimsAndLowercasesEachPermission(): void
    {
        $perm = new Permission(['  Admin  ', "\tviewer\n"]);

        self::assertSame(['admin', 'viewer'], $perm->getPermissions());
    }

    public function testConstructorSilentlySkipsNonStringValues(): void
    {
        /** @phpstan-ignore-next-line — exercising the runtime guard */
        $perm = new Permission(['admin', 42, null, 'editor', ['nested']]);

        self::assertSame(['admin', 'editor'], $perm->getPermissions());
    }

    public function testIsReturnsFalseForEmptyPermissionSet(): void
    {
        $perm = new Permission();

        self::assertFalse($perm->is('admin'));
    }

    public function testIsReturnsTrueWhenAnyOfTheSuppliedNamesMatches(): void
    {
        $perm = new Permission(['admin']);

        self::assertTrue($perm->is('editor', 'admin', 'viewer'));
    }

    public function testPushAddsNewPermissionsAndReportsCount(): void
    {
        $perm = new Permission(['admin']);

        self::assertSame(2, $perm->push('editor', 'Viewer'));
        self::assertSame(['admin', 'editor', 'viewer'], $perm->getPermissions());
    }

    public function testPushIsIdempotentAndIgnoresAlreadyPresentPermissions(): void
    {
        $perm = new Permission(['admin']);

        self::assertSame(0, $perm->push('admin', 'ADMIN', 'Admin'));
        self::assertSame(['admin'], $perm->getPermissions());
    }

    public function testRemoveReportsCountAndZeroForMissingPermissions(): void
    {
        $perm = new Permission(['admin', 'editor']);

        self::assertSame(1, $perm->remove('admin', 'viewer'));
        self::assertSame(0, $perm->remove('viewer'));
        self::assertSame(['editor'], $perm->getPermissions());
    }

    /**
     * Regression: remove() previously left a hole in the internal array
     * because unset() does not reindex. A subsequent getPermissions() call
     * then exposed a non-sequential array which broke JSON encoding and
     * any caller that relied on list semantics.
     */
    public function testRemoveReindexesPermissionList(): void
    {
        $perm = new Permission(['admin', 'editor', 'viewer']);
        $perm->remove('editor');

        self::assertSame(['admin', 'viewer'], $perm->getPermissions());
    }

    public function testDeprecatedGetPermissionAliasReturnsSameList(): void
    {
        $perm = new Permission(['admin', 'editor']);

        self::assertSame($perm->getPermissions(), $perm->getPermission());
    }

    public function testMagicIsPrefixedCallDelegatesToIs(): void
    {
        $perm = new Permission(['admin']);

        /** @phpstan-ignore-next-line — magic accessor */
        self::assertTrue($perm->is_admin());
        /** @phpstan-ignore-next-line */
        self::assertFalse($perm->is_editor());
    }

    public function testMagicCallRejectsUnknownMethodNames(): void
    {
        $perm = new Permission();

        $this->expectException(BadMethodCallException::class);
        /** @phpstan-ignore-next-line */
        $perm->doSomething();
    }

    public function testMagicCallRejectsEmptyIsPrefix(): void
    {
        $perm = new Permission(['admin']);

        /** @phpstan-ignore-next-line — bare `is_` resolves to empty name and must be falsy */
        self::assertFalse($perm->is_());
    }

    public function testMagicIssetSupportsBareAndIsPrefixedAccess(): void
    {
        $perm = new Permission(['admin']);

        self::assertTrue(isset($perm->admin));
        self::assertTrue(isset($perm->is_admin));
        self::assertFalse(isset($perm->editor));
    }

    public function testMagicUnsetRemovesPermission(): void
    {
        $perm = new Permission(['admin', 'editor']);

        unset($perm->is_admin);

        self::assertSame(['editor'], $perm->getPermissions());
    }

    public function testSerializationRoundtripPreservesPermissionList(): void
    {
        $original = new Permission(['Admin', 'editor']);
        /** @var Permission $restored */
        $restored = \unserialize(\serialize($original));

        self::assertSame(['admin', 'editor'], $restored->getPermissions());
        self::assertTrue($restored->is('admin'));
    }
}
