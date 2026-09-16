<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * Bulk read model used by observation-worker. No detector performs per-server
 * queries: a run loads each required historical shape once and groups in PHP.
 */
final class ObservationAnalysisRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{
     *   server_id:int,server_name:string,metric_id:int,metric_name:string,
     *   warning_threshold:float,p10:float,median:float,p90:float,points:int,
     *   first_at:string,last_at:string
     * }>
     */
    public function levelBaselines(DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                hourly.server_id,
                servers.name AS server_name,
                hourly.metric_id,
                metric_names.name AS metric_name,
                COALESCE(
                    thresholds.warning_threshold,
                    (SELECT CAST(setting_value #>> '{}' AS double precision)
                     FROM app_settings
                     WHERE setting_key = 'default_warning_threshold'),
                    70.0
                ) AS warning_threshold,
                percentile_cont(0.10) WITHIN GROUP (ORDER BY hourly.avg_value) AS p10,
                percentile_cont(0.50) WITHIN GROUP (ORDER BY hourly.avg_value) AS median,
                percentile_cont(0.90) WITHIN GROUP (ORDER BY hourly.avg_value) AS p90,
                count(*) AS points,
                min(hourly.bucket) AS first_at,
                max(hourly.bucket) AS last_at
            FROM metric_samples_hourly AS hourly
            INNER JOIN servers ON servers.id = hourly.server_id
            INNER JOIN metric_names ON metric_names.id = hourly.metric_id
            LEFT JOIN metric_thresholds AS thresholds
              ON thresholds.server_id = hourly.server_id
             AND thresholds.metric_id = hourly.metric_id
            WHERE servers.is_active = TRUE
              AND metric_names.name IN ('cpu_load', 'ram_used')
              AND hourly.bucket >= :baseline_start
              AND hourly.bucket < :baseline_end
              AND NOT EXISTS (
                  SELECT 1
                  FROM maintenance_windows AS maintenance
                  WHERE maintenance.server_id = servers.id
                    AND maintenance.starts_at <= CURRENT_TIMESTAMP
                    AND maintenance.ends_at > CURRENT_TIMESTAMP
              )
            GROUP BY
                hourly.server_id,
                servers.name,
                hourly.metric_id,
                metric_names.name,
                thresholds.warning_threshold
            HAVING count(*) >= 24
            ORDER BY hourly.server_id, metric_names.name
            SQL
        );
        $statement->execute([
            'baseline_start' => $this->timestamp($now->modify('-14 days')),
            'baseline_end' => $this->timestamp($now->modify('-2 hours')),
        ]);
        return array_map(
            static fn (array $row): array => [
                'server_id' => (int) $row['server_id'],
                'server_name' => (string) $row['server_name'],
                'metric_id' => (int) $row['metric_id'],
                'metric_name' => (string) $row['metric_name'],
                'warning_threshold' => (float) $row['warning_threshold'],
                'p10' => (float) $row['p10'],
                'median' => (float) $row['median'],
                'p90' => (float) $row['p90'],
                'points' => (int) $row['points'],
                'first_at' => (string) $row['first_at'],
                'last_at' => (string) $row['last_at'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return list<array{server_id:int,metric_id:int,metric_name:string,time:string,value:float}>
     */
    public function recentLevelBuckets(DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                samples.server_id,
                samples.metric_id,
                metric_names.name AS metric_name,
                time_bucket(INTERVAL '5 minutes', samples.sample_time) AS bucket,
                avg(samples.value) AS value
            FROM metric_samples AS samples
            INNER JOIN servers ON servers.id = samples.server_id
            INNER JOIN metric_names ON metric_names.id = samples.metric_id
            WHERE servers.is_active = TRUE
              AND metric_names.name IN ('cpu_load', 'ram_used')
              AND samples.sample_time >= :range_start
              AND samples.sample_time <= :range_end
              AND NOT EXISTS (
                  SELECT 1
                  FROM maintenance_windows AS maintenance
                  WHERE maintenance.server_id = servers.id
                    AND maintenance.starts_at <= CURRENT_TIMESTAMP
                    AND maintenance.ends_at > CURRENT_TIMESTAMP
              )
            GROUP BY samples.server_id, samples.metric_id, metric_names.name, bucket
            ORDER BY samples.server_id, samples.metric_id, bucket
            SQL
        );
        $statement->execute([
            'range_start' => $this->timestamp($now->modify('-2 hours')),
            'range_end' => $this->timestamp($now),
        ]);

        return array_map(
            static fn (array $row): array => [
                'server_id' => (int) $row['server_id'],
                'metric_id' => (int) $row['metric_id'],
                'metric_name' => (string) $row['metric_name'],
                'time' => (string) $row['bucket'],
                'value' => (float) $row['value'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return list<array{
     *   server_id:int,server_name:string,metric_id:int,metric_name:string,
     *   warning_threshold:float,current_value:float,time:string,value:float
     * }>
     */
    public function diskHistory(DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                hourly.server_id,
                servers.name AS server_name,
                hourly.metric_id,
                metric_names.name AS metric_name,
                COALESCE(
                    thresholds.warning_threshold,
                    (SELECT CAST(setting_value #>> '{}' AS double precision)
                     FROM app_settings
                     WHERE setting_key = 'default_warning_threshold'),
                    70.0
                ) AS warning_threshold,
                current_values.value AS current_value,
                hourly.bucket,
                hourly.avg_value AS value
            FROM metric_samples_hourly AS hourly
            INNER JOIN servers ON servers.id = hourly.server_id
            INNER JOIN metric_names ON metric_names.id = hourly.metric_id
            INNER JOIN current_metric_values AS current_values
              ON current_values.server_id = hourly.server_id
             AND current_values.metric_id = hourly.metric_id
            LEFT JOIN metric_thresholds AS thresholds
              ON thresholds.server_id = hourly.server_id
             AND thresholds.metric_id = hourly.metric_id
            WHERE servers.is_active = TRUE
              AND metric_names.name LIKE 'disk_used_%'
              AND metric_names.name <> 'disk_used'
              AND hourly.bucket >= :range_start
              AND hourly.bucket <= :range_end
              AND NOT EXISTS (
                  SELECT 1
                  FROM maintenance_windows AS maintenance
                  WHERE maintenance.server_id = servers.id
                    AND maintenance.starts_at <= CURRENT_TIMESTAMP
                    AND maintenance.ends_at > CURRENT_TIMESTAMP
              )
            ORDER BY hourly.server_id, hourly.metric_id, hourly.bucket
            SQL
        );
        $statement->execute([
            'range_start' => $this->timestamp($now->modify('-14 days')),
            'range_end' => $this->timestamp($now),
        ]);

        return array_map(
            static fn (array $row): array => [
                'server_id' => (int) $row['server_id'],
                'server_name' => (string) $row['server_name'],
                'metric_id' => (int) $row['metric_id'],
                'metric_name' => (string) $row['metric_name'],
                'warning_threshold' => (float) $row['warning_threshold'],
                'current_value' => (float) $row['current_value'],
                'time' => (string) $row['bucket'],
                'value' => (float) $row['value'],
            ],
            $statement->fetchAll()
        );
    }

    private function timestamp(DateTimeInterface $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }
}
