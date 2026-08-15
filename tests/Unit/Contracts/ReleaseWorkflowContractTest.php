<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase
{
    public function testFourComponentHotfixTagsArePublishedLikeNormalReleases(): void
    {
        $workflow = (string) file_get_contents(
            dirname(__DIR__, 3) . '/.github/workflows/ci.yml'
        );

        self::assertStringContainsString(
            '- "v[0-9]+.[0-9]+.[0-9]+.[0-9]+"',
            $workflow
        );
        self::assertSame(
            2,
            substr_count(
                $workflow,
                'type=match,pattern=^v([0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+)$,group=1'
            )
        );
        self::assertSame(
            2,
            substr_count(
                $workflow,
                'type=match,pattern=^v([0-9]+\\.[0-9]+\\.[0-9]+)\\.[0-9]+$,group=1'
            )
        );
        self::assertSame(
            2,
            substr_count(
                $workflow,
                'type=match,pattern=^v([0-9]+\\.[0-9]+)\\.[0-9]+\\.[0-9]+$,group=1'
            )
        );
        self::assertSame(
            2,
            substr_count(
                $workflow,
                'type=match,pattern=^v([0-9]+)\\.[0-9]+\\.[0-9]+\\.[0-9]+$,group=1'
            )
        );
        self::assertSame(
            2,
            substr_count(
                $workflow,
                "type=raw,value=latest,enable=\${{ !contains(github.ref_name, '-') }}"
            )
        );
    }
}
