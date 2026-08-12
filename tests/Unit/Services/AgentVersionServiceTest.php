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
        yield 'prerelease to release' => ['v0.4.3-rc.1', 'v0.4.3', true];
        yield 'release to prerelease' => ['v0.4.3', 'v0.4.4-rc.1', true];
        yield 'development installed' => ['development', 'v0.4.3', false];
        yield 'development available' => ['v0.4.2', 'development', false];
        yield 'leading zero' => ['v0.04.2', 'v0.4.3', false];
    }

    #[DataProvider('comparisons')]
    public function testOnlyStrictlyNewerSemverIsAnUpgrade(
        string $installed,
        string $available,
        bool $expected
    ): void {
        self::assertSame(
            $expected,
            (new AgentVersionService())->isUpgrade($installed, $available)
        );
    }
}
