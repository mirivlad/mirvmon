<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DiskMetricAliasResolver;
use PHPUnit\Framework\TestCase;

final class DiskMetricAliasResolverTest extends TestCase
{
    public function testCoalescesMatchingFilesystemAliasesToRoot(): void
    {
        $resolver = new DiskMetricAliasResolver();
        $points = $this->points(55.0, 0.5);
        $result = $resolver->coalesce([
            $this->group(1, 10, 'disk_used_tmp', 70.0, 61.0, 80.0, $points),
            $this->group(1, 11, 'disk_used_root', 75.0, 61.0, 80.0, $points),
            $this->group(1, 12, 'disk_used_var_tmp', 72.0, 61.0, 80.0, $points),
        ]);

        self::assertCount(1, $result['groups']);
        self::assertSame('disk_used_root', $result['groups'][0]['metric_name']);
        self::assertSame(70.0, $result['groups'][0]['warning_threshold']);
        self::assertSame(
            ['disk_used_tmp', 'disk_used_var_tmp'],
            $result['groups'][0]['aliases']
        );
        self::assertEqualsCanonicalizing(['1:10', '1:12'], $result['alias_metric_keys']);
    }

    public function testKeepsDifferentSizedFilesystemsSeparate(): void
    {
        $resolver = new DiskMetricAliasResolver();
        $points = $this->points(55.0, 0.5);
        $result = $resolver->coalesce([
            $this->group(1, 10, 'disk_used_root', 70.0, 61.0, 80.0, $points),
            $this->group(1, 11, 'disk_used_data', 70.0, 61.0, 160.0, $points),
        ]);

        self::assertCount(2, $result['groups']);
        self::assertSame([], $result['alias_metric_keys']);
    }

    public function testKeepsDivergingHistoriesSeparate(): void
    {
        $resolver = new DiskMetricAliasResolver();
        $rootPoints = $this->points(55.0, 0.5);
        $dataPoints = $rootPoints;
        $dataPoints[5]['value'] += 0.01;
        $result = $resolver->coalesce([
            $this->group(1, 10, 'disk_used_root', 70.0, 61.0, 80.0, $rootPoints),
            $this->group(1, 11, 'disk_used_data', 70.0, 61.0, 80.0, $dataPoints),
        ]);

        self::assertCount(2, $result['groups']);
        self::assertSame([], $result['alias_metric_keys']);
    }

    /** @return list<array{time:string,value:float}> */
    private function points(float $start, float $step): array
    {
        $points = [];
        $time = new \DateTimeImmutable('2026-09-10T00:00:00Z');
        for ($index = 0; $index < 48; $index++) {
            $points[] = [
                'time' => $time->modify('+' . $index . ' hours')->format(DATE_ATOM),
                'value' => $start + $step * $index,
            ];
        }
        return $points;
    }

    /**
     * @param list<array{time:string,value:float}> $points
     * @return array<string, mixed>
     */
    private function group(
        int $serverId,
        int $metricId,
        string $metricName,
        float $warningThreshold,
        float $currentValue,
        float $totalGb,
        array $points
    ): array {
        return [
            'server_id' => $serverId,
            'server_name' => 'server-' . $serverId,
            'metric_id' => $metricId,
            'metric_name' => $metricName,
            'warning_threshold' => $warningThreshold,
            'current_value' => $currentValue,
            'total_gb' => $totalGb,
            'points' => $points,
        ];
    }
}
