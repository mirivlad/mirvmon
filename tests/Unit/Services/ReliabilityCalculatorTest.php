<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ReliabilityCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReliabilityCalculatorTest extends TestCase
{
    public function testUnknownTimeBeforeFirstEventIsNotCountedAsAvailable(): void
    {
        $result = (new ReliabilityCalculator())->serverAvailability(
            [
                ['state' => 'online', 'occurred_at' => '2026-09-24T00:20:00+00:00'],
                ['state' => 'offline', 'occurred_at' => '2026-09-24T00:40:00+00:00'],
            ],
            new DateTimeImmutable('2026-09-24T00:00:00+00:00'),
            new DateTimeImmutable('2026-09-24T01:00:00+00:00')
        );

        self::assertSame(2400, $result['known_seconds']);
        self::assertSame(1200, $result['online_seconds']);
        self::assertSame(1200, $result['offline_seconds']);
        self::assertSame(50.0, $result['availability_percent']);
        self::assertSame(1, $result['outages']);
    }

    public function testPreviousStateCarriesIntoWindowWithoutInventingAnOutage(): void
    {
        $result = (new ReliabilityCalculator())->serverAvailability(
            [
                ['state' => 'offline', 'occurred_at' => '2026-09-23T23:50:00+00:00'],
                ['state' => 'online', 'occurred_at' => '2026-09-24T00:10:00+00:00'],
            ],
            new DateTimeImmutable('2026-09-24T00:00:00+00:00'),
            new DateTimeImmutable('2026-09-24T01:00:00+00:00')
        );

        self::assertSame(600, $result['offline_seconds']);
        self::assertSame(3000, $result['online_seconds']);
        self::assertSame(0, $result['outages']);
        self::assertSame(83.33, $result['availability_percent']);
    }

    public function testCoverageUsesExpectedSamplesAndDoesNotExceedOneHundredPercent(): void
    {
        $calculator = new ReliabilityCalculator();
        $from = new DateTimeImmutable('2026-09-24T00:00:00+00:00');
        $to = new DateTimeImmutable('2026-09-24T01:00:00+00:00');

        self::assertSame(
            ['observed' => 12, 'expected' => 60, 'coverage_percent' => 20.0],
            $calculator->coverage(12, $from, $to, 60)
        );
        self::assertSame(100.0, $calculator->coverage(61, $from, $to, 60)['coverage_percent']);
    }
}
