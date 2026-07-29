<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ThresholdEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThresholdEvaluatorTest extends TestCase
{
    #[DataProvider('severityCases')]
    public function testSeverity(
        float $value,
        ?float $warning,
        ?float $critical,
        ?string $expected
    ): void {
        self::assertSame(
            $expected,
            (new ThresholdEvaluator())->severity($value, $warning, $critical)
        );
    }

    /** @return iterable<string, array{float, ?float, ?float, ?string}> */
    public static function severityCases(): iterable
    {
        yield 'normal' => [69.9, 70, 90, null];
        yield 'warning boundary' => [70, 70, 90, 'warning'];
        yield 'critical boundary' => [90, 70, 90, 'critical'];
        yield 'critical only' => [100, null, 90, 'critical'];
        yield 'warning only' => [80, 70, null, 'warning'];
        yield 'disabled' => [100, null, null, null];
    }
}
