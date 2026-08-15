<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class ReleaseHotfixDocumentationTest extends TestCase
{
    public function testHotfixReleaseAliasesAreDocumented(): void
    {
        $documentation = (string) file_get_contents(
            dirname(__DIR__, 3) . '/docs/release-hotfix-tags.md'
        );

        foreach (['0.4.15.2', '0.4.15', '0.4', '0', 'latest', 'sha-*'] as $tag) {
            self::assertStringContainsString($tag, $documentation);
        }
    }
}
