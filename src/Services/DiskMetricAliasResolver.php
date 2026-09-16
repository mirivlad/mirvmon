<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class DiskMetricAliasResolver
{
    private const MIN_COMMON_POINTS = 8;
    private const MIN_COMMON_SPAN_SECONDS = 86400;
    private const MIN_COVERAGE = 0.80;
    private const VALUE_EPSILON = 0.001;
    private const TOTAL_ABS_EPSILON_GB = 0.01;
    private const TOTAL_REL_EPSILON = 0.0001;

    /**
     * @param list<array<string, mixed>> $groups
     * @return array{groups:list<array<string, mixed>>,alias_metric_keys:list<string>}
     */
    public function coalesce(array $groups): array
    {
        $byServer = [];
        foreach ($groups as $group) {
            $byServer[(int) $group['server_id']][] = $group;
        }

        $result = [];
        $aliasMetricKeys = [];
        foreach ($byServer as $serverGroups) {
            [$resolved, $aliases] = $this->coalesceServer($serverGroups);
            array_push($result, ...$resolved);
            array_push($aliasMetricKeys, ...$aliases);
        }

        return ['groups' => $result, 'alias_metric_keys' => $aliasMetricKeys];
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @return array{0:list<array<string, mixed>>,1:list<string>}
     */
    private function coalesceServer(array $groups): array
    {
        $count = count($groups);
        $parents = range(0, max(0, $count - 1));
        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                if ($this->areAliases($groups[$left], $groups[$right])) {
                    $this->union($parents, $left, $right);
                }
            }
        }

        $clusters = [];
        foreach ($groups as $index => $group) {
            $clusters[$this->find($parents, $index)][] = $group;
        }

        $result = [];
        $aliasMetricKeys = [];
        foreach ($clusters as $cluster) {
            usort($cluster, [$this, 'compareCanonical']);
            $canonical = array_shift($cluster);
            if (!is_array($canonical)) {
                continue;
            }
            if ($cluster !== []) {
                $canonical['warning_threshold'] = min(array_map(
                    static fn (array $group): float => (float) $group['warning_threshold'],
                    array_merge([$canonical], $cluster)
                ));
                $canonical['aliases'] = array_values(array_map(
                    static fn (array $group): string => (string) $group['metric_name'],
                    $cluster
                ));
                foreach ($cluster as $alias) {
                    $aliasMetricKeys[] = $this->metricKey(
                        (int) $alias['server_id'],
                        (int) $alias['metric_id']
                    );
                }
            } else {
                $canonical['aliases'] = [];
            }
            $result[] = $canonical;
        }

        return [$result, $aliasMetricKeys];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function areAliases(array $left, array $right): bool
    {
        if ((int) $left['server_id'] !== (int) $right['server_id']) {
            return false;
        }
        $leftTotal = $left['total_gb'] ?? null;
        $rightTotal = $right['total_gb'] ?? null;
        if (!is_numeric($leftTotal) || !is_numeric($rightTotal)) {
            return false;
        }
        $leftTotal = (float) $leftTotal;
        $rightTotal = (float) $rightTotal;
        $totalTolerance = max(
            self::TOTAL_ABS_EPSILON_GB,
            max(abs($leftTotal), abs($rightTotal)) * self::TOTAL_REL_EPSILON
        );
        if (abs($leftTotal - $rightTotal) > $totalTolerance) {
            return false;
        }
        if (abs((float) $left['current_value'] - (float) $right['current_value']) > self::VALUE_EPSILON) {
            return false;
        }

        $leftPoints = $this->pointsByTime($left['points'] ?? []);
        $rightPoints = $this->pointsByTime($right['points'] ?? []);
        $commonTimes = array_values(array_intersect(array_keys($leftPoints), array_keys($rightPoints)));
        sort($commonTimes);
        if (count($commonTimes) < self::MIN_COMMON_POINTS) {
            return false;
        }
        $coverage = count($commonTimes) / max(1, min(count($leftPoints), count($rightPoints)));
        if ($coverage < self::MIN_COVERAGE) {
            return false;
        }
        $first = new DateTimeImmutable($commonTimes[0]);
        $last = new DateTimeImmutable($commonTimes[array_key_last($commonTimes)]);
        if ($last->getTimestamp() - $first->getTimestamp() < self::MIN_COMMON_SPAN_SECONDS) {
            return false;
        }
        foreach ($commonTimes as $time) {
            if (abs($leftPoints[$time] - $rightPoints[$time]) > self::VALUE_EPSILON) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param mixed $points
     * @return array<string, float>
     */
    private function pointsByTime(mixed $points): array
    {
        if (!is_array($points)) {
            return [];
        }
        $mapped = [];
        foreach ($points as $point) {
            if (!is_array($point) || !isset($point['time'], $point['value'])) {
                continue;
            }
            $mapped[(string) $point['time']] = (float) $point['value'];
        }
        return $mapped;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareCanonical(array $left, array $right): int
    {
        $leftName = (string) $left['metric_name'];
        $rightName = (string) $right['metric_name'];
        $leftRank = $leftName === 'disk_used_root' ? 0 : 1;
        $rightRank = $rightName === 'disk_used_root' ? 0 : 1;

        return $leftRank <=> $rightRank ?: strcmp($leftName, $rightName);
    }

    /** @param array<int, int> $parents */
    private function find(array &$parents, int $index): int
    {
        while ($parents[$index] !== $index) {
            $parents[$index] = $parents[$parents[$index]];
            $index = $parents[$index];
        }
        return $index;
    }

    /** @param array<int, int> $parents */
    private function union(array &$parents, int $left, int $right): void
    {
        $leftRoot = $this->find($parents, $left);
        $rightRoot = $this->find($parents, $right);
        if ($leftRoot !== $rightRoot) {
            $parents[$rightRoot] = $leftRoot;
        }
    }

    private function metricKey(int $serverId, int $metricId): string
    {
        return $serverId . ':' . $metricId;
    }
}
