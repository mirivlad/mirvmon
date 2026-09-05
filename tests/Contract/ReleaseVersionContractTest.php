<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class ReleaseVersionContractTest extends TestCase
{
    public function testPinnedProductionExamplesMatchLatestChangelogRelease(): void
    {
        $root = dirname(__DIR__, 2);
        $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
        self::assertSame(1, preg_match('/^## ([0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?)$/m', $changelog, $match));
        $image = 'ghcr.io/mirivlad/mirvmon:' . $match[1];

        foreach ([
            '.env.example',
            'docker/.env.example',
            'README.md',
            'INSTALL.md',
        ] as $file) {
            self::assertStringContainsString(
                $image,
                (string) file_get_contents($root . '/' . $file),
                $file
            );
        }
    }
}
