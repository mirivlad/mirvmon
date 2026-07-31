<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Metrics;

use App\Domain\Metrics\MetricValueFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricValueFormatterTest extends TestCase
{
    #[DataProvider('throughputProvider')]
    public function testThroughputScalesToTheLargestReadableUnit(
        float $value,
        string $expected
    ): void {
        self::assertSame(
            $expected,
            (new MetricValueFormatter())->format($value, 'B/s')
        );
    }

    /** @return iterable<string, array{float, string}> */
    public static function throughputProvider(): iterable
    {
        yield 'idle interface' => [0.0, '0 B/s'];
        yield 'below one kilobyte' => [512.0, '512 B/s'];
        yield 'exactly one kilobyte' => [1024.0, '1 KB/s'];
        yield 'megabytes' => [12_500_000.0, '11.92 MB/s'];
        yield 'gigabytes' => [3.0 * 1024 ** 3, '3 GB/s'];
        yield 'terabytes' => [2.5 * 1024 ** 4, '2.5 TB/s'];
        yield 'beyond the largest unit' => [4096.0 * 1024 ** 4, '4096 TB/s'];
    }

    public function testOtherUnitsKeepTheirSuffixAndPercentIsNotScaled(): void
    {
        $formatter = new MetricValueFormatter();

        self::assertSame('87.25%', $formatter->format(87.2537, '%'));
        self::assertSame('4096%', $formatter->format(4096.0, '%'));
        self::assertSame('41.5°C', $formatter->format(41.5, '°C'));
        self::assertSame('16', $formatter->format(16.0, null));
        self::assertSame('0.5', $formatter->format(0.5, ''));
    }
}
