<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ExternalConnectivityProbe;
use PHPUnit\Framework\TestCase;

final class ExternalConnectivityProbeTest extends TestCase
{
    public function testTwoOfThreeSuccessfulTargetsSatisfyQuorum(): void
    {
        $calls = [];
        $probe = new ExternalConnectivityProbe(
            ['one:443', 'two:443', 'three:443'],
            2,
            0.5,
            static function (string $target, float $timeout) use (&$calls): bool {
                $calls[] = [$target, $timeout];
                return $target !== 'two:443';
            },
        );

        $result = $probe->probe();

        self::assertTrue($result['available']);
        self::assertSame(2, $result['successes']);
        self::assertSame(1, $result['failures']);
        self::assertSame(['one:443', 'three:443'], $result['successful_targets']);
        self::assertSame(['two:443'], $result['failed_targets']);
        self::assertCount(3, $calls);
    }

    public function testTwoOfThreeFailuresMakeConnectivityUnavailable(): void
    {
        $calls = [];
        $probe = new ExternalConnectivityProbe(
            ['one:443', 'two:443', 'three:443'],
            2,
            0.5,
            static function (string $target) use (&$calls): bool {
                $calls[] = $target;
                return false;
            },
        );

        $result = $probe->probe();

        self::assertFalse($result['available']);
        self::assertSame(0, $result['successes']);
        self::assertSame(3, $result['failures']);
        self::assertSame(['one:443', 'two:443', 'three:443'], $calls);
    }
}
