<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeInterface;
use InvalidArgumentException;
use PDO;

final class MetricRepository
{
    private const RAW_MAX_SECONDS = 172800;
    private const HOURLY_MAX_SECONDS = 7776000;
    private const MAX_POINTS = 500;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $metricNames
     * @return array<string, array{value: float, unit: ?string, sample_time: string}>
     */
    public function latestValues(int $serverId, array $metricNames = []): array
    {
        [$filter, $params] = $this->metricFilter($metricNames, 'names.name');
        $statement = $this->pdo->prepare(
            <<<SQL
            SELECT
                names.name,
                names.unit,
                current_values.value,
                current_values.sample_time
            FROM current_metric_values AS current_values
            INNER JOIN metric_names AS names ON names.id = current_values.metric_id
            WHERE current_values.server_id = :server_id
            {$filter}
            ORDER BY names.name
            SQL
        );
        $statement->execute(['server_id' => $serverId, ...$params]);

        $metrics = [];
        foreach ($statement->fetchAll() as $row) {
            $metrics[$row['name']] = [
                'value' => (float) $row['value'],
                'unit' => $row['unit'] === null ? null : (string) $row['unit'],
                'sample_time' => (string) $row['sample_time'],
            ];
        }

        return $metrics;
    }

    /**
     * @param list<string> $metricNames
     * @return array{
     *     source: 'raw'|'hourly'|'daily',
     *     bucket_seconds: int,
     *     points: list<array{
     *         name: string,
     *         unit: ?string,
     *         time_bucket: string,
     *         value: float,
     *         min_value: float,
     *         max_value: float,
     *         sample_count: int
     *     }>
     * }
     */
    public function series(
        int $serverId,
        DateTimeInterface $start,
        DateTimeInterface $end,
        array $metricNames = []
    ): array {
        $durationSeconds = $end->getTimestamp() - $start->getTimestamp();
        if ($durationSeconds <= 0) {
            throw new InvalidArgumentException('Metric range end must be after start.');
        }

        $source = $this->sourceForRange($start, $end);
        $bucketSeconds = $this->bucketSeconds($durationSeconds, $source);
        [$filter, $metricParams] = $this->metricFilter($metricNames, 'names.name');

        if ($source === 'raw') {
            $sql = <<<SQL
                SELECT
                    names.name,
                    names.unit,
                    time_bucket(
                        CAST(:bucket_seconds AS integer) * INTERVAL '1 second',
                        samples.sample_time
                    ) AS time_bucket,
                    avg(samples.value) AS value,
                    min(samples.value) AS min_value,
                    max(samples.value) AS max_value,
                    count(*) AS sample_count
                FROM metric_samples AS samples
                INNER JOIN metric_names AS names ON names.id = samples.metric_id
                WHERE samples.server_id = :server_id
                  AND samples.sample_time >= :range_start
                  AND samples.sample_time <= :range_end
                  {$filter}
                GROUP BY names.name, names.unit, time_bucket
                ORDER BY time_bucket, names.name
                SQL;
        } else {
            $table = $source === 'hourly'
                ? 'metric_samples_hourly'
                : 'metric_samples_daily';
            $sql = <<<SQL
                SELECT
                    names.name,
                    names.unit,
                    time_bucket(
                        CAST(:bucket_seconds AS integer) * INTERVAL '1 second',
                        aggregates.bucket
                    ) AS time_bucket,
                    sum(aggregates.avg_value * aggregates.sample_count)
                        / NULLIF(sum(aggregates.sample_count), 0) AS value,
                    min(aggregates.min_value) AS min_value,
                    max(aggregates.max_value) AS max_value,
                    sum(aggregates.sample_count) AS sample_count
                FROM {$table} AS aggregates
                INNER JOIN metric_names AS names ON names.id = aggregates.metric_id
                WHERE aggregates.server_id = :server_id
                  AND aggregates.bucket >= :range_start
                  AND aggregates.bucket <= :range_end
                  {$filter}
                GROUP BY names.name, names.unit, time_bucket
                ORDER BY time_bucket, names.name
                SQL;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'server_id' => $serverId,
            'range_start' => $start->format(DateTimeInterface::ATOM),
            'range_end' => $end->format(DateTimeInterface::ATOM),
            'bucket_seconds' => $bucketSeconds,
            ...$metricParams,
        ]);

        $points = [];
        foreach ($statement->fetchAll() as $row) {
            $points[] = [
                'name' => (string) $row['name'],
                'unit' => $row['unit'] === null ? null : (string) $row['unit'],
                'time_bucket' => (string) $row['time_bucket'],
                'value' => (float) $row['value'],
                'min_value' => (float) $row['min_value'],
                'max_value' => (float) $row['max_value'],
                'sample_count' => (int) $row['sample_count'],
            ];
        }

        return [
            'source' => $source,
            'bucket_seconds' => $bucketSeconds,
            'points' => $points,
        ];
    }

    /** @return 'raw'|'hourly'|'daily' */
    public function sourceForRange(DateTimeInterface $start, DateTimeInterface $end): string
    {
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Metric range end must be after start.');
        }
        if ($seconds <= self::RAW_MAX_SECONDS) {
            return 'raw';
        }
        if ($seconds <= self::HOURLY_MAX_SECONDS) {
            return 'hourly';
        }

        return 'daily';
    }

    /** @return list<array{id: int, name: string, unit: ?string}> */
    public function metricTypes(int $serverId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT metric_types.id, metric_types.name, metric_types.unit
            FROM (
                SELECT DISTINCT
                    names.id,
                    names.name,
                    names.unit,
                    CASE
                        WHEN names.name = 'cpu_load' THEN 1
                        WHEN names.name = 'ram_used' THEN 2
                        WHEN names.name LIKE 'disk_used_%' THEN 3
                        WHEN names.name LIKE 'net_in_%' THEN 4
                        WHEN names.name LIKE 'net_out_%' THEN 5
                        WHEN names.name LIKE 'temp_%' THEN 6
                        ELSE 7
                    END AS sort_order
                FROM metric_names AS names
                INNER JOIN metric_samples AS samples ON samples.metric_id = names.id
                WHERE samples.server_id = :server_id
                  AND names.name <> 'uptime'
                  AND (
                      names.name IN ('cpu_load', 'ram_used')
                      OR names.name LIKE 'disk_used_%'
                      OR names.name LIKE 'net_in_%'
                      OR names.name LIKE 'net_out_%'
                      OR names.name LIKE 'temp_%'
                  )
            ) AS metric_types
            ORDER BY metric_types.sort_order, metric_types.name
            SQL
        );
        $statement->execute(['server_id' => $serverId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'unit' => $row['unit'] === null ? null : (string) $row['unit'],
            ],
            $statement->fetchAll()
        );
    }

    /** @return array{value: float, created_at: string}|null */
    public function latestUptime(int $serverId): ?array
    {
        $latest = $this->latestValues($serverId, ['uptime']);
        if (!isset($latest['uptime'])) {
            return null;
        }

        return [
            'value' => $latest['uptime']['value'],
            'created_at' => $latest['uptime']['sample_time'],
        ];
    }

    /**
     * @param list<string> $metricNames
     * @return array{string, array<string, string>}
     */
    private function metricFilter(array $metricNames, string $column): array
    {
        if ($metricNames === []) {
            return ['', []];
        }

        $metricNames = array_values(array_unique($metricNames));
        $placeholders = [];
        $params = [];
        foreach ($metricNames as $index => $name) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Metric names must be non-empty strings.');
            }

            $key = 'metric_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $name;
        }

        return [
            sprintf('AND %s IN (%s)', $column, implode(', ', $placeholders)),
            $params,
        ];
    }

    /** @param 'raw'|'hourly'|'daily' $source */
    private function bucketSeconds(int $durationSeconds, string $source): int
    {
        $minimum = match ($source) {
            'raw' => 60,
            'hourly' => 3600,
            'daily' => 86400,
        };
        $target = max($minimum, (int) ceil($durationSeconds / self::MAX_POINTS));
        $steps = [
            60,
            300,
            900,
            1800,
            3600,
            7200,
            14400,
            21600,
            43200,
            86400,
            172800,
            604800,
            2592000,
        ];

        foreach ($steps as $step) {
            if ($step >= $target && $step >= $minimum) {
                return $step;
            }
        }

        return (int) (ceil($target / 2592000) * 2592000);
    }
}
