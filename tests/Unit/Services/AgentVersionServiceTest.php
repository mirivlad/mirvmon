<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AgentVersionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentVersionServiceTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function comparisons(): iterable
    {
        yield 'upgrade' => ['v0.4.2', 'v0.4.3', true];
        yield 'equal' => ['v0.4.3', 'v0.4.3', false];
        yield 'downgrade' => ['v0.4.4', 'v0.4.3', false];
        yield 'hotfix upgrade' => ['v0.4.15.1', 'v0.4.15.2', true];
        yield 'patch to hotfix upgrade' => ['v0.4.15', 'v0.4.15.1', true];
        yield 'hotfix to next patch upgrade' => ['v0.4.15.9', 'v0.4.16', true];
        yield 'hotfix downgrade' => ['v0.4.15.2', 'v0.4.15.1', false];
        yield 'prerelease to release' => ['v0.4.3-rc.1', 'v0.4.3', true];
        yield 'release to prerelease' => ['v0.4.3', 'v0.4.4-rc.1', true];
        yield 'development installed' => ['development', 'v0.4.3', false];
        yield 'development available' => ['v0.4.2', 'development', false];
        yield 'leading zero' => ['v0.04.2', 'v0.4.3', false];
        yield 'hotfix leading zero' => ['v0.4.15.01', 'v0.4.15.2', false];
    }

    #[DataProvider('comparisons')]
    public function testOnlyStrictlyNewerReleaseVersionIsAnUpgrade(
        string $installed,
        string $available,
        bool $expected
    ): void {
        self::assertSame(
            $expected,
            (new AgentVersionService())->isUpgrade($installed, $available)
        );
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function selfUpdateTargets(): iterable
    {
        yield 'old updater may still receive normal release' => ['v0.4.12', 'v0.4.17', true];
        yield 'old updater must not receive four-part hotfix' => ['v0.4.12', 'v0.4.17.1', false];
        yield 'last incompatible updater must not receive hotfix' => ['v0.4.15.2', 'v0.4.17.1', false];
        yield 'first compatible updater accepts hotfix' => ['v0.4.15.3', 'v0.4.17.1', true];
        yield 'modern three-part agent accepts hotfix' => ['v0.4.17', 'v0.4.17.1', true];
        yield 'modern hotfix agent accepts later hotfix' => ['v0.4.17.1', 'v0.4.17.2', true];
        yield 'invalid installed version is rejected' => ['development', 'v0.4.17.1', false];
    }

    #[DataProvider('selfUpdateTargets')]
    public function testSelfUpdateTargetCompatibility(
        string $installed,
        string $available,
        bool $expected
    ): void {
        self::assertSame(
            $expected,
            (new AgentVersionService())->supportsSelfUpdateTarget($installed, $available)
        );
    }
}
