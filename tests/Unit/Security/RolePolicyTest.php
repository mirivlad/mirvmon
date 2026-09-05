<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\RolePolicy;
use PHPUnit\Framework\TestCase;

final class RolePolicyTest extends TestCase
{
    public function testRoleCatalogIsExplicitAndStable(): void
    {
        self::assertSame(['admin', 'operator', 'user'], RolePolicy::roles());
        self::assertTrue(RolePolicy::isKnown('admin'));
        self::assertTrue(RolePolicy::isKnown('operator'));
        self::assertTrue(RolePolicy::isKnown('user'));
        self::assertFalse(RolePolicy::isKnown('viewer'));
        self::assertFalse(RolePolicy::isKnown(null));
    }

    public function testAdminCapabilityIsAdminOnly(): void
    {
        self::assertTrue(RolePolicy::isAdmin('admin'));
        self::assertFalse(RolePolicy::isAdmin('operator'));
        self::assertFalse(RolePolicy::isAdmin('user'));
        self::assertFalse(RolePolicy::isAdmin(null));
    }

    public function testOperationalCapabilityIncludesOperatorAndAdmin(): void
    {
        self::assertTrue(RolePolicy::canOperate('admin'));
        self::assertTrue(RolePolicy::canOperate('operator'));
        self::assertFalse(RolePolicy::canOperate('user'));
        self::assertFalse(RolePolicy::canOperate('unknown'));
        self::assertFalse(RolePolicy::canOperate(null));
    }
}
