<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Context-aware CPU/RAM level-shift detector.
 *
 * Detection and recovery are deliberately separate decisions. A missing trigger
 * is not recovery: an open episode is resolved only after a sustained run below
 * its recovery boundary.
 */
final class ContextualLevelShiftAnalyzer
{
    private const RECENT_BUCKETS = 6;
    private const SUSTAINED_BUCKETS = 4;
    private const RECURRENT_BUCKETS = 6;
    private const RECOVERY_BUCKETS = 12; // one hour of 5-minute buckets
    private const MIN_ABSOLUTE_DELTA = 8.0;
    private const MIN_BASELINE_POINTS = 6;
    private const MIN_BASELINE_SPAN_SECONDS = 259200; // 3 days

    /**
     * @param array<string, mixed> $baseline
     * @param list<array{time:string,value:float}> $buckets
     * @param list<array<string,mixed>> $approvedPatterns
     * @param array<string,mixed>|null $openEpisode
     * @return array{state:string,candidate:?array<string,mixed>,evidence:array<string,mixed>}
     */
    public function evaluate(
        string $metric,
        float $warningThreshold,
        array $baseline,
        array $buckets,
        DateTimeImmutable $now,
        array $approvedPatterns = [],
        ?array $openEpisode = null
    ): array {
        if (!in_array($metric, ['cpu_load', 'ram_used'], true)) {
            throw new InvalidArgumentException('Contextual level-shift detector supports CPU and RAM only.');
        }
        if ($warningThreshold <= 0.0 || $warningThreshold > 100.0) {
            throw new InvalidArgumentException('Warning threshold must be between 0 and 100.');
        }

        $buckets = $this->orderedPoints($buckets);
        if (
            (int) ($baseline['points'] ?? 0) < self::MIN_BASELINE_POINTS
            || count($buckets) < self::RECENT_BUCKETS
            || !$this->baselineSpanIsReady($baseline)
        ) {
            return [
                'state' => 'insufficient_data',
                'candidate' => null,
                'evidence' => [],
            ];
        }

        $p10 = (float) $baseline['p10'];
        $median = (float) $baseline['median'];
        $p90 = (float) $baseline['p90'];
        $robustSpread = max(1.0, ($p90 - $p10) / 2.0);
        $triggerBoundary = max(
            $p90 + max(4.0, 0.25 * $robustSpread),
            $median + max(self::MIN_ABSOLUTE_DELTA, abs($median) * 0.50)
        );
        $recoveryBoundary = min(
            $triggerBoundary - 1.0,
            max($p90, $median + max(3.0, $robustSpread))
        );

        $values = array_map('floatval', array_column($buckets, 'value'));
        $recentValues = array_slice($values, -self::RECENT_BUCKETS);
        $latestValue = (float) $recentValues[array_key_last($recentValues)];
        $recentPeak = max($recentValues);
        $localWeekday = (int) ($baseline['local_weekday'] ?? (int) $now->format('N'));
        $localHour = (int) ($baseline['local_hour'] ?? (int) $now->format('G'));
        $context = (string) ($baseline['context'] ?? 'global');
        $weeks = (int) ($baseline['weeks'] ?? 0);
        $baselineQuality = $this->baselineQuality($context, (int) $baseline['points'], $weeks);

        $evidence = [
            'metric' => $metric,
            'baseline_context' => $context,
            'baseline_points' => (int) $baseline['points'],
            'baseline_weeks' => $weeks,
            'baseline_timezone' => (string) ($baseline['timezone'] ?? 'UTC'),
            'context_weekday' => $localWeekday,
            'context_hour' => $localHour,
            'baseline_p10' => round($p10, 3),
            'baseline_median' => round($median, 3),
            'baseline_p90' => round($p90, 3),
            'expected_low' => round($p10, 3),
            'expected_high' => round($p90, 3),
            'trigger_boundary' => round($triggerBoundary, 3),
            'recovery_boundary' => round($recoveryBoundary, 3),
            'warning_threshold' => round($warningThreshold, 3),
            'current_value' => round($latestValue, 3),
            'baseline_value' => round($median, 3),
            'recent_min' => round(min($recentValues), 3),
            'recent_peak' => round($recentPeak, 3),
            'baseline_quality' => round($baselineQuality, 4),
        ];

        if ($recentPeak >= $warningThreshold) {
            $evidence['lifecycle_state'] = 'incident_owned';
            return ['state' => 'incident_owned', 'candidate' => null, 'evidence' => $evidence];
        }

        $recoveryValues = array_slice($values, -self::RECOVERY_BUCKETS);
        if (
            count($recoveryValues) >= self::RECOVERY_BUCKETS
            && count(array_filter(
                $recoveryValues,
                static fn (float $value): bool => $value <= $recoveryBoundary
            )) === self::RECOVERY_BUCKETS
        ) {
            $evidence['lifecycle_state'] = 'clear';
            return ['state' => 'clear', 'candidate' => null, 'evidence' => $evidence];
        }

        $triggerFlags = array_map(
            static fn (float $value): bool => $value >= $triggerBoundary,
            $values
        );
        $lastFlags = array_slice($triggerFlags, -self::RECENT_BUCKETS);
        $sustainedCount = count(array_filter($lastFlags));
        $totalAnomalous = count(array_filter($triggerFlags));
        $clusters = $this->trueClusters($triggerFlags);

        $reason = null;
        if ($sustainedCount >= self::SUSTAINED_BUCKETS) {
            $reason = 'sustained';
        } elseif (
            $totalAnomalous >= self::RECURRENT_BUCKETS
            && $clusters >= 2
            && count(array_filter(array_slice($triggerFlags, -2))) > 0
        ) {
            $reason = 'recurrent';
        }

        if ($reason === null) {
            $evidence['lifecycle_state'] = 'elevated';
            return ['state' => 'elevated', 'candidate' => null, 'evidence' => $evidence];
        }

        $observed = $reason === 'sustained'
            ? array_sum($recentValues) / count($recentValues)
            : max($values);

        if ($this->matchesApprovedPattern(
            $approvedPatterns,
            $metric,
            $localWeekday,
            $localHour,
            $observed,
            $warningThreshold
        )) {
            $evidence['lifecycle_state'] = $openEpisode === null ? 'approved' : 'elevated';
            return [
                'state' => $openEpisode === null ? 'approved' : 'elevated',
                'candidate' => null,
                'evidence' => $evidence,
            ];
        }

        $severity = min(
            1.0,
            max(0.0, ($observed - $triggerBoundary) / max(5.0, $triggerBoundary - $median))
        );
        $confidence = min(0.99, 0.55 + 0.24 * $severity + 0.20 * $baselineQuality);
        $valueBand = (int) (round($observed / 5.0) * 5);
        $fingerprint = sprintf(
            'level_shift_v2:%s:d%d:h%02d:b%d',
            $metric,
            $localWeekday,
            $localHour,
            $valueBand
        );

        $details = $evidence + [
            'reason' => $reason,
            'lifecycle_state' => 'triggered',
            'recent_bucket_count' => count($buckets),
            'anomalous_bucket_count' => $totalAnomalous,
            'anomalous_cluster_count' => $clusters,
        ];

        return [
            'state' => 'triggered',
            'candidate' => [
                'kind' => 'anomaly',
                'detector' => 'level_shift_v2',
                'fingerprint' => $fingerprint,
                'current_value' => round($observed, 3),
                'baseline_value' => round($median, 3),
                'confidence' => round($confidence, 4),
                'forecast_at' => null,
                'details' => $details,
            ],
            'evidence' => $details,
        ];
    }

    /** @param array<string,mixed> $baseline */
    private function baselineSpanIsReady(array $baseline): bool
    {
        if (!isset($baseline['first_at'], $baseline['last_at'])) {
            return false;
        }
        $first = new DateTimeImmutable((string) $baseline['first_at']);
        $last = new DateTimeImmutable((string) $baseline['last_at']);
        return $last->getTimestamp() - $first->getTimestamp() >= self::MIN_BASELINE_SPAN_SECONDS;
    }

    /**
     * @param list<array<string,mixed>> $patterns
     */
    private function matchesApprovedPattern(
        array $patterns,
        string $metric,
        int $weekday,
        int $hour,
        float $observed,
        float $warningThreshold
    ): bool {
        foreach ($patterns as $pattern) {
            $details = is_array($pattern['details'] ?? null) ? $pattern['details'] : [];
            $detector = (string) ($pattern['detector'] ?? '');

            if ($detector === 'level_shift_v2') {
                if ((string) ($details['metric'] ?? '') !== $metric) {
                    continue;
                }
                $patternWeekday = (int) ($details['context_weekday'] ?? 0);
                $patternHour = (int) ($details['context_hour'] ?? -1);
                if ($patternWeekday !== $weekday || $this->hourDistance($patternHour, $hour) > 1) {
                    continue;
                }
                $patternCurrent = (float) ($pattern['current_value'] ?? 0.0);
                $patternMin = (float) ($details['recent_min'] ?? $patternCurrent);
                $patternPeak = (float) ($details['recent_peak'] ?? $patternCurrent);
                $lower = max(0.0, min($patternMin - 5.0, $patternCurrent * 0.75));
                $upper = min(
                    $warningThreshold,
                    max($patternPeak + 5.0, $patternCurrent * 1.25)
                );
                if ($observed >= $lower && $observed <= $upper) {
                    return true;
                }
                continue;
            }

            if ($detector === 'level_shift_v1') {
                $fingerprint = (string) ($pattern['fingerprint'] ?? '');
                if (preg_match(
                    '/^level_shift_v1:' . preg_quote($metric, '/') . ':p(\d+):b(\d+)$/',
                    $fingerprint,
                    $matches
                ) !== 1) {
                    continue;
                }
                $dayPart = intdiv($hour, 6);
                $band = (int) (round($observed / 5.0) * 5);
                if ((int) $matches[1] === $dayPart && abs((int) $matches[2] - $band) <= 5) {
                    return true;
                }
            }
        }

        return false;
    }

    private function baselineQuality(string $context, int $points, int $weeks): float
    {
        $contextWeight = match ($context) {
            'weekly_hour' => 1.0,
            'day_type_hour' => 0.90,
            'hour_of_day' => 0.80,
            default => 0.65,
        };
        $coverage = min(1.0, max(0.35, $weeks / 6.0));
        $density = min(1.0, max(0.50, $points / 24.0));
        return $contextWeight * (0.55 + 0.25 * $coverage + 0.20 * $density);
    }

    /**
     * @param list<array{time:string,value:float}> $points
     * @return list<array{time:string,value:float}>
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

    private function hourDistance(int $left, int $right): int
    {
        $distance = abs($left - $right);
        return min($distance, 24 - $distance);
    }
}
