<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Deterministic, explainable detectors for proactive observations.
 *
 * This class deliberately has no database dependency: the statistical contract
 * can be exercised with fixtures and the worker can evolve its storage/query
 * strategy without changing detector semantics.
 */
final class ObservationAnalyzer
{
    private const LEVEL_MIN_BASELINE_POINTS = 24;
    private const LEVEL_MIN_BASELINE_SPAN_SECONDS = 259200; // 3 days
    private const LEVEL_RECENT_BUCKETS = 6;
    private const LEVEL_SUSTAINED_BUCKETS = 4;
    private const LEVEL_RECURRENT_BUCKETS = 6;
    private const LEVEL_MIN_ABSOLUTE_DELTA = 8.0;
    private const DISK_RESET_DELTA = 5.0;
    private const DISK_MIN_SPAN_SECONDS = 86400; // 1 day
    private const DISK_MIN_POINTS = 8;
    private const DISK_MIN_SLOPE_PER_DAY = 0.25;
    private const DISK_MIN_R2 = 0.65;
    private const DISK_WARNING_HORIZON_DAYS = 7.0;
    private const DISK_FULL_HORIZON_DAYS = 14.0;

    /**
     * @param array{p10: float, median: float, p90: float, points: int, first_at: string, last_at: string} $baseline
     * @param list<array{time: string, value: float}> $buckets
     * @return array<string, mixed>|null
     */
    public function detectLevelShift(
        string $metric,
        float $warningThreshold,
        array $baseline,
        array $buckets,
        DateTimeImmutable $now
    ): ?array {
        if (!in_array($metric, ['cpu_load', 'ram_used'], true)) {
            throw new InvalidArgumentException('Level-shift detector supports CPU and RAM only.');
        }
        if ($warningThreshold <= 0.0 || $warningThreshold > 100.0) {
            throw new InvalidArgumentException('Warning threshold must be between 0 and 100.');
        }
        if ((int) $baseline['points'] < self::LEVEL_MIN_BASELINE_POINTS) {
            return null;
        }

        $first = new DateTimeImmutable((string) $baseline['first_at']);
        $last = new DateTimeImmutable((string) $baseline['last_at']);
        if ($last->getTimestamp() - $first->getTimestamp() < self::LEVEL_MIN_BASELINE_SPAN_SECONDS) {
            return null;
        }

        $buckets = $this->orderedPoints($buckets);
        if (count($buckets) < self::LEVEL_RECENT_BUCKETS) {
            return null;
        }

        $p10 = (float) $baseline['p10'];
        $median = (float) $baseline['median'];
        $p90 = (float) $baseline['p90'];
        $robustSpread = max(1.0, ($p90 - $p10) / 2.0);
        $medianDelta = max(self::LEVEL_MIN_ABSOLUTE_DELTA, abs($median) * 0.50);
        $boundary = max(
            $p90 + max(4.0, 2.0 * $robustSpread),
            $median + $medianDelta
        );

        $values = array_column($buckets, 'value');
        if (max($values) >= $warningThreshold) {
            // Once the configured threshold is involved, incidents own the signal.
            return null;
        }

        $anomalous = array_map(
            static fn (float $value): bool => $value >= $boundary,
            $values
        );
        $lastFlags = array_slice($anomalous, -self::LEVEL_RECENT_BUCKETS);
        $sustainedCount = count(array_filter($lastFlags));
        $totalAnomalous = count(array_filter($anomalous));
        $clusters = $this->trueClusters($anomalous);

        $reason = null;
        if ($sustainedCount >= self::LEVEL_SUSTAINED_BUCKETS) {
            $reason = 'sustained';
        } elseif ($totalAnomalous >= self::LEVEL_RECURRENT_BUCKETS && $clusters >= 2) {
            $reason = 'recurrent';
        }
        if ($reason === null) {
            return null;
        }

        $recentValues = array_slice($values, -self::LEVEL_RECENT_BUCKETS);
        $observed = $reason === 'sustained'
            ? array_sum($recentValues) / count($recentValues)
            : max($values);
        $delta = max(0.0, $observed - $median);
        $confidence = min(
            0.99,
            0.55
                + min(0.24, ($delta / max(1.0, $boundary - $median)) * 0.12)
                + min(0.20, $totalAnomalous / max(1, count($anomalous)) * 0.35)
        );
        $valueBand = (int) (round($observed / 5.0) * 5);
        $dayPart = intdiv((int) $now->format('G'), 6);

        return [
            'kind' => 'anomaly',
            'detector' => 'level_shift_v1',
            'fingerprint' => sprintf(
                'level_shift_v1:%s:p%d:b%d',
                $metric,
                $dayPart,
                $valueBand
            ),
            'current_value' => round($observed, 3),
            'baseline_value' => round($median, 3),
            'confidence' => round($confidence, 4),
            'forecast_at' => null,
            'details' => [
                'metric' => $metric,
                'reason' => $reason,
                'baseline_p10' => round($p10, 3),
                'baseline_median' => round($median, 3),
                'baseline_p90' => round($p90, 3),
                'anomaly_boundary' => round($boundary, 3),
                'warning_threshold' => round($warningThreshold, 3),
                'recent_peak' => round(max($values), 3),
                'recent_bucket_count' => count($buckets),
                'anomalous_bucket_count' => $totalAnomalous,
                'anomalous_cluster_count' => $clusters,
            ],
        ];
    }

    /**
     * @param list<array{time: string, value: float}> $points
     * @return array<string, mixed>|null
     */
    public function detectDiskGrowth(
        string $metric,
        float $warningThreshold,
        array $points,
        float $currentValue,
        DateTimeImmutable $now
    ): ?array {
        if (!str_starts_with($metric, 'disk_used_') || $metric === 'disk_used') {
            throw new InvalidArgumentException('Disk-growth detector requires a disk_used_* metric.');
        }
        if ($warningThreshold <= 0.0 || $warningThreshold > 100.0) {
            throw new InvalidArgumentException('Warning threshold must be between 0 and 100.');
        }
        if ($currentValue >= $warningThreshold || $currentValue < 0.0 || $currentValue > 100.0) {
            return null;
        }

        $points = $this->orderedPoints($points);
        if ($points === []) {
            return null;
        }
        $points[] = ['time' => $now->format(DATE_ATOM), 'value' => $currentValue];
        $points = $this->orderedPoints($points);

        $segmentStart = 0;
        for ($index = 1, $count = count($points); $index < $count; $index++) {
            if ($points[$index - 1]['value'] - $points[$index]['value'] >= self::DISK_RESET_DELTA) {
                $segmentStart = $index;
            }
        }
        $segment = array_slice($points, $segmentStart);
        if (count($segment) < self::DISK_MIN_POINTS) {
            return null;
        }

        $firstAt = new DateTimeImmutable($segment[0]['time']);
        $lastAt = new DateTimeImmutable($segment[array_key_last($segment)]['time']);
        $spanSeconds = $lastAt->getTimestamp() - $firstAt->getTimestamp();
        if ($spanSeconds < self::DISK_MIN_SPAN_SECONDS) {
            return null;
        }

        $fit = $this->linearFit($segment, $firstAt);
        if ($fit['slope'] < self::DISK_MIN_SLOPE_PER_DAY || $fit['r2'] < self::DISK_MIN_R2) {
            return null;
        }

        $daysToWarning = ($warningThreshold - $currentValue) / $fit['slope'];
        $daysToFull = (100.0 - $currentValue) / $fit['slope'];
        if ($daysToWarning <= 0.0 || $daysToFull <= 0.0) {
            return null;
        }
        if (
            $daysToWarning > self::DISK_WARNING_HORIZON_DAYS
            && $daysToFull > self::DISK_FULL_HORIZON_DAYS
        ) {
            return null;
        }

        $warningAt = $now->modify(sprintf('+%d seconds', (int) round($daysToWarning * 86400)));
        $fullAt = $now->modify(sprintf('+%d seconds', (int) round($daysToFull * 86400)));
        $spanDays = $spanSeconds / 86400.0;
        $confidence = min(0.99, 0.40 + 0.40 * $fit['r2'] + 0.20 * min(1.0, $spanDays / 7.0));
        $forecastAt = $daysToWarning <= self::DISK_WARNING_HORIZON_DAYS ? $warningAt : $fullAt;

        return [
            'kind' => 'prediction',
            'detector' => 'disk_growth_v1',
            'fingerprint' => 'disk_growth_v1:' . $metric,
            'current_value' => round($currentValue, 3),
            'baseline_value' => round($segment[0]['value'], 3),
            'confidence' => round($confidence, 4),
            'forecast_at' => $forecastAt->format(DATE_ATOM),
            'details' => [
                'metric' => $metric,
                'warning_threshold' => round($warningThreshold, 3),
                'slope_percent_per_day' => round($fit['slope'], 4),
                'r2' => round($fit['r2'], 4),
                'trend_span_days' => round($spanDays, 3),
                'trend_points' => count($segment),
                'predicted_warning_at' => $warningAt->format(DATE_ATOM),
                'predicted_full_at' => $fullAt->format(DATE_ATOM),
            ],
        ];
    }

    /**
     * @param list<array{time: string, value: float}> $points
     * @return list<array{time: string, value: float}>
     */
    private function orderedPoints(array $points): array
    {
        usort(
            $points,
            static fn (array $left, array $right): int => strcmp($left['time'], $right['time'])
        );
        return $points;
    }

    /** @param list<bool> $flags */
    private function trueClusters(array $flags): int
    {
        $clusters = 0;
        $inside = false;
        foreach ($flags as $flag) {
            if ($flag && !$inside) {
                $clusters++;
                $inside = true;
                continue;
            }
            if (!$flag) {
                $inside = false;
            }
        }
        return $clusters;
    }

    /**
     * @param list<array{time: string, value: float}> $points
     * @return array{slope: float, intercept: float, r2: float}
     */
    private function linearFit(array $points, DateTimeImmutable $origin): array
    {
        $xs = [];
        $ys = [];
        foreach ($points as $point) {
            $time = new DateTimeImmutable($point['time']);
            $xs[] = ($time->getTimestamp() - $origin->getTimestamp()) / 86400.0;
            $ys[] = (float) $point['value'];
        }
        $count = count($xs);
        $meanX = array_sum($xs) / $count;
        $meanY = array_sum($ys) / $count;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($xs as $index => $x) {
            $dx = $x - $meanX;
            $sxx += $dx * $dx;
            $sxy += $dx * ($ys[$index] - $meanY);
        }
        if ($sxx <= 0.0) {
            return ['slope' => 0.0, 'intercept' => $meanY, 'r2' => 0.0];
        }
        $slope = $sxy / $sxx;
        $intercept = $meanY - $slope * $meanX;
        $ssTotal = 0.0;
        $ssResidual = 0.0;
        foreach ($xs as $index => $x) {
            $predicted = $intercept + $slope * $x;
            $ssTotal += ($ys[$index] - $meanY) ** 2;
            $ssResidual += ($ys[$index] - $predicted) ** 2;
        }
        $r2 = $ssTotal <= 1.0e-12 ? 0.0 : max(0.0, min(1.0, 1.0 - $ssResidual / $ssTotal));

        return ['slope' => $slope, 'intercept' => $intercept, 'r2' => $r2];
    }
}
