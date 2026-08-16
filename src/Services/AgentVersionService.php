<?php

declare(strict_types=1);

namespace App\Services;

final class AgentVersionService
{
    private const RELEASE_VERSION = '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:\.(0|[1-9][0-9]*))?(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/';
    private const HOTFIX_SELF_UPDATE_MINIMUM = 'v0.4.15.3';

    public function isUpgrade(string $installed, string $available): bool
    {
        if (!$this->isReleaseVersion($installed) || !$this->isReleaseVersion($available)) {
            return false;
        }

        return version_compare(substr($available, 1), substr($installed, 1), '>');
    }

    public function supportsSelfUpdateTarget(string $installed, string $available): bool
    {
        if (!$this->isReleaseVersion($installed) || !$this->isReleaseVersion($available)) {
            return false;
        }
        if (!$this->isFourPartRelease($available)) {
            return true;
        }

        return version_compare(
            substr($installed, 1),
            substr(self::HOTFIX_SELF_UPDATE_MINIMUM, 1),
            '>='
        );
    }

    private function isReleaseVersion(string $version): bool
    {
        return preg_match(self::RELEASE_VERSION, $version) === 1;
    }

    private function isFourPartRelease(string $version): bool
    {
        $matches = [];
        if (preg_match(self::RELEASE_VERSION, $version, $matches) !== 1) {
            return false;
        }

        return isset($matches[4]) && $matches[4] !== '';
    }
}
