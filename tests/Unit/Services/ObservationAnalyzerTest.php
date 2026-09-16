<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ObservationAnalyzer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ObservationAnalyzerTest extends TestCase
{
    private ObservationAnalyzer $analyzer;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->analyzer = new ObservationAnalyzer();
        $this->now = new DateTimeImmutable('2026-09-16T03:00:00+00:00');
    }

    public function testStableCpuDoesNotCreateObservation(): void
    {
        self::assertNull($this->analyzer->detectLevelShift(
            'cpu_load',
            70.0,
            $this->baseline(2.0, 2.6, 3.2),
            $this->recent([2.5, 3.1, 2.8, 3.0, 2.6, 3.2]),
            $this->now
        ));
    }

    public function testSingleCpuSpikeDoesNotCreateObservation(): void
    {
        self::assertNull($this->analyzer->detectLevelShift(
            'cpu_load',
            70.0,
            $this->baseline(2.0, 2.6, 3.2),
            $this->recent([2.5, 3.0, 22.0, 2.8, 3.1, 2.9]),
            $this->now
        ));
    }

    public function testSustainedCpuShiftCreatesExplainableObservation(): void
    {
        $candidate = $this->analyzer->detectLevelShift(
            'cpu_load',
            70.0,
            $this->baseline(2.0, 2.6, 3.2),
            $this->recent([3.0, 3.0, 18.0, 20.0, 21.0, 19.0]),
            $this->now
        );

        self::assertNotNull($candidate);
        self::assertSame('anomaly', $candidate['kind']);
        self::assertSame('level_shift_v1', $candidate['detector']);
        self::assertSame('sustained', $candidate['details']['reason']);
        self::assertSame(2.6, $candidate['baseline_value']);
        self::assertLessThan(70.0, $candidate['current_value']);
        self::assertStringContainsString('cpu_load', $candidate['fingerprint']);
    }

    public function testRecurringCpuSpikesCreateOnePatternCandidate(): void
    {
        $candidate = $this->analyzer->detectLevelShift(
            'cpu_load',
            70.0,
            $this->baseline(2.0, 2.6, 3.2),
            $this->recent([20, 21, 3, 19, 20, 3, 18, 19, 3, 3, 20, 3]),
            $this->now
        );

        self::assertNotNull($candidate);
        self::assertSame('recurrent', $candidate['details']['reason']);
        self::assertGreaterThanOrEqual(2, $candidate['details']['anomalous_cluster_count']);
    }

    public function testLevelShiftStandsDownWhenWarningThresholdIsReached(): void
    {
        self::assertNull($this->analyzer->detectLevelShift(
            'ram_used',
            70.0,
            $this->baseline(30.0, 31.0, 32.0),
            $this->recent([31, 31, 60, 62, 71, 68]),
            $this->now
        ));
    }

    public function testYoungBaselineDoesNotArmDetector(): void
    {
        $baseline = $this->baseline(2.0, 2.6, 3.2);
        $baseline['first_at'] = $this->now->modify('-2 days')->format(DATE_ATOM);

        self::assertNull($this->analyzer->detectLevelShift(
            'cpu_load',
            70.0,
            $baseline,
            $this->recent([18, 19, 20, 21, 19, 20]),
            $this->now
        ));
    }

    public function testDiskGrowthForecastsThresholdBeforeAlert(): void
    {
        $points = [];
        for ($day = 7; $day >= 1; $day--) {
            $points[] = [
                'time' => $this->now->modify('-' . $day . ' days')->format(DATE_ATOM),
                'value' => 55.0 + (7 - $day) * 2.0,
            ];
        }

        $candidate = $this->analyzer->detectDiskGrowth(
            'disk_used_root',
            75.0,
            $points,
            69.0,
            $this->now
        );

        self::assertNotNull($candidate);
        self::assertSame('prediction', $candidate['kind']);
        self::assertSame('disk_growth_v1', $candidate['detector']);
        self::assertGreaterThan(1.0, $candidate['details']['slope_percent_per_day']);
        self::assertGreaterThan(0.9, $candidate['details']['r2']);
        self::assertNotNull($candidate['forecast_at']);
    }

    public function testDiskCleanupStartsNewTrendSegment(): void
    {
        $points = [
            ['time' => $this->now->modify('-10 days')->format(DATE_ATOM), 'value' => 82.0],
            ['time' => $this->now->modify('-9 days')->format(DATE_ATOM), 'value' => 86.0],
        ];
        for ($day = 8; $day >= 1; $day--) {
            $points[] = [
                'time' => $this->now->modify('-' . $day . ' days')->format(DATE_ATOM),
                'value' => 40.0 + (8 - $day) * 2.0,
            ];
        }

        $candidate = $this->analyzer->detectDiskGrowth(
            'disk_used_root',
            70.0,
            $points,
            56.0,
            $this->now
        );

        self::assertNotNull($candidate);
        self::assertLessThan(9.0, $candidate['details']['trend_span_days']);
        self::assertGreaterThan(1.5, $candidate['details']['slope_percent_per_day']);
    }

    public function testFlatDiskDoesNotCreatePrediction(): void
    {
        $points = [];
        for ($day = 7; $day >= 1; $day--) {
            $points[] = [
                'time' => $this->now->modify('-' . $day . ' days')->format(DATE_ATOM),
                'value' => 52.0 + (($day % 2) * 0.2),
            ];
        }

        self::assertNull($this->analyzer->detectDiskGrowth(
            'disk_used_root',
            75.0,
            $points,
            52.1,
            $this->now
        ));
    }

    /** @return array{p10: float, median: float, p90: float, points: int, first_at: string, last_at: string} */
    private function baseline(float $p10, float $median, float $p90): array
    {
        return [
            'p10' => $p10,
            'median' => $median,
            'p90' => $p90,
            'points' => 96,
            'first_at' => $this->now->modify('-7 days')->format(DATE_ATOM),
            'last_at' => $this->now->modify('-2 hours')->format(DATE_ATOM),
        ];
    }

    /** @param list<float|int> $values @return list<array{time: string, value: float}> */
    private function recent(array $values): array
    {
        $points = [];
        $start = $this->now->modify('-' . (count($values) * 5) . ' minutes');
        foreach ($values as $index => $value) {
            $points[] = [
                'time' => $start->modify('+' . ($index * 5) . ' minutes')->format(DATE_ATOM),
                'value' => (float) $value,
            ];
        }
        return $points;
    }
}
