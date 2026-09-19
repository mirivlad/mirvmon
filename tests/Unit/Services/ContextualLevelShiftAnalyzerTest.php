<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ContextualLevelShiftAnalyzer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ContextualLevelShiftAnalyzerTest extends TestCase
{
    private ContextualLevelShiftAnalyzer $analyzer;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->analyzer = new ContextualLevelShiftAnalyzer();
        $this->now = new DateTimeImmutable('2026-09-19T10:00:00+08:00');
    }

    public function testWideDaytimePatternIsNotReportedAsAnomaly(): void
    {
        $evaluation = $this->analyzer->evaluate(
            'cpu_load',
            80.0,
            $this->baseline(25.0, 45.0, 68.0, 6, 10),
            $this->recent([35, 52, 67, 43, 60, 30]),
            $this->now
        );

        self::assertNotSame('triggered', $evaluation['state']);
        self::assertNull($evaluation['candidate']);
        self::assertSame('weekly_hour', $evaluation['evidence']['baseline_context']);
        self::assertSame(68.0, $evaluation['evidence']['expected_high']);
    }

    public function testSameLoadTriggersAgainstNightBaseline(): void
    {
        $evaluation = $this->analyzer->evaluate(
            'cpu_load',
            70.0,
            $this->baseline(4.0, 8.5, 11.0, 6, 2),
            $this->recent([9, 10, 25, 28, 30, 27]),
            $this->now
        );

        self::assertSame('triggered', $evaluation['state']);
        self::assertNotNull($evaluation['candidate']);
        self::assertSame('level_shift_v2', $evaluation['candidate']['detector']);
        self::assertSame('sustained', $evaluation['candidate']['details']['reason']);
        self::assertStringContainsString(':d6:h02:', $evaluation['candidate']['fingerprint']);
    }

    public function testTriggerFallingAwayWhileStillElevatedDoesNotMeanRecovery(): void
    {
        $evaluation = $this->analyzer->evaluate(
            'cpu_load',
            70.0,
            $this->baseline(4.0, 8.5, 11.0, 6, 2),
            $this->recent([30, 28, 25, 22, 18, 16, 14, 13, 13, 14, 13, 14]),
            $this->now
        );

        self::assertSame('elevated', $evaluation['state']);
        self::assertNull($evaluation['candidate']);
    }

    public function testRecoveryRequiresFullHourBelowRecoveryBoundary(): void
    {
        $clear = $this->analyzer->evaluate(
            'cpu_load',
            70.0,
            $this->baseline(4.0, 8.5, 11.0, 6, 2),
            $this->recent([10, 11, 9, 10, 11, 10, 9, 10, 11, 10, 9, 10]),
            $this->now
        );
        $notClear = $this->analyzer->evaluate(
            'cpu_load',
            70.0,
            $this->baseline(4.0, 8.5, 11.0, 6, 2),
            $this->recent([10, 11, 9, 10, 11, 10, 9, 10, 11, 10, 9, 14]),
            $this->now
        );

        self::assertSame('clear', $clear['state']);
        self::assertSame('elevated', $notClear['state']);
    }

    public function testWarningThresholdOwnershipIsNotRecovery(): void
    {
        $evaluation = $this->analyzer->evaluate(
            'cpu_load',
            70.0,
            $this->baseline(4.0, 8.5, 11.0, 6, 2),
            $this->recent([30, 35, 42, 72, 55, 40]),
            $this->now
        );

        self::assertSame('incident_owned', $evaluation['state']);
    }

    public function testApprovedWeeklyPatternSuppressesMatchingContextOnly(): void
    {
        $pattern = [[
            'detector' => 'level_shift_v2',
            'fingerprint' => 'level_shift_v2:cpu_load:d6:h02:b30',
            'current_value' => 30.0,
            'details' => [
                'metric' => 'cpu_load',
                'context_weekday' => 6,
                'context_hour' => 2,
                'recent_min' => 24.0,
                'recent_peak' => 38.0,
            ],
        ]];

        $approved = $this->analyzer->evaluate(
            'cpu_load',
            70.0,
            $this->baseline(4.0, 8.5, 11.0, 6, 2),
            $this->recent([10, 11, 25, 28, 30, 27]),
            $this->now,
            $pattern
        );
        $otherWeekday = $this->analyzer->evaluate(
            'cpu_load',
            70.0,
            $this->baseline(4.0, 8.5, 11.0, 5, 2),
            $this->recent([10, 11, 25, 28, 30, 27]),
            $this->now,
            $pattern
        );

        self::assertSame('approved', $approved['state']);
        self::assertSame('triggered', $otherWeekday['state']);
    }

    public function testRecoveryUsesOpeningEpisodeBoundaryAcrossContextChange(): void
    {
        $evaluation = $this->analyzer->evaluate(
            'cpu_load',
            80.0,
            $this->baseline(25.0, 45.0, 68.0, 6, 10),
            $this->recent([20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20]),
            $this->now,
            [],
            ['details' => ['recovery_boundary' => 12.0]]
        );

        self::assertSame('elevated', $evaluation['state']);
        self::assertSame(12.0, $evaluation['evidence']['recovery_boundary']);
    }

    /** @return array<string,mixed> */
    private function baseline(
        float $p10,
        float $median,
        float $p90,
        int $weekday,
        int $hour
    ): array {
        return [
            'p10' => $p10,
            'median' => $median,
            'p90' => $p90,
            'points' => 24,
            'weeks' => 8,
            'first_at' => $this->now->modify('-49 days')->format(DATE_ATOM),
            'last_at' => $this->now->modify('-7 days')->format(DATE_ATOM),
            'context' => 'weekly_hour',
            'timezone' => 'Asia/Irkutsk',
            'local_weekday' => $weekday,
            'local_hour' => $hour,
        ];
    }

    /** @param list<int|float> $values @return list<array{time:string,value:float}> */
    private function recent(array $values): array
    {
        $start = $this->now->modify('-' . (count($values) * 5) . ' minutes');
        $result = [];
        foreach ($values as $index => $value) {
            $result[] = [
                'time' => $start->modify('+' . ($index * 5) . ' minutes')->format(DATE_ATOM),
                'value' => (float) $value,
            ];
        }
        return $result;
    }
}
