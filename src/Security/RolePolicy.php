<?php

declare(strict_types=1);

namespace App\Security;

final class RolePolicy
{
    public const ADMIN = 'admin';
    public const OPERATOR = 'operator';
    public const USER = 'user';

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ADMIN, self::OPERATOR, self::USER];
    }

    public static function isKnown(mixed $role): bool
    {
        return is_string($role) && in_array($role, self::roles(), true);
    }

    public static function isAdmin(mixed $role): bool
    {
        return $role === self::ADMIN;
    }

    public static function canOperate(mixed $role): bool
    {
        return $role === self::ADMIN || $role === self::OPERATOR;
    }
}
