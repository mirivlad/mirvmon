<?php

declare(strict_types=1);

namespace App\Services;

final class AgentVersionService
{
    private const SEMVER = '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/';

    public function isUpgrade(string $installed, string $available): bool
    {
        if (
            preg_match(self::SEMVER, $installed) !== 1
            || preg_match(self::SEMVER, $available) !== 1
        ) {
            return false;
        }

        return version_compare(substr($available, 1), substr($installed, 1), '>');
    }
}
