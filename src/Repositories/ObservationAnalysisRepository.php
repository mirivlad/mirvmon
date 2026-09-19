<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use PDO;

/**
 * Bulk read model used by observation-worker. No detector performs per-server
 * queries: a run loads each required historical shape once and groups in PHP.
 */
final class ObservationAnalysisRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $timezone = 'UTC'
    ) {
        new DateTimeZone($this->timezone);
    }

    /**
     * @return list<array{
     *   server_id:int,server_name:string,metric_id:int,metric_name:string,
     *   warning_threshold:float,p10:float,median:float,p90:float,points:int,
     *   weeks:int,first_at:string,last_at:string,context:string,timezone:string,
     *   local_weekday:int,local_hour:int
     * }>
     */
    public function levelBaselines(DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            WITH settings AS (
                SELECT
                    CAST(:timezone AS text) AS timezone,
                    CAST(:now AS timestamptz) AS now_utc
            ),
            context AS (
                SELECT timezone, now_utc AT TIME ZONE timezone AS local_now
                FROM settings
            ),
            base AS (
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
                    hourly.bucket,
                    hourly.avg_value,
                    hourly.min_value,
                    hourly.max_value,
                    context.timezone,
                    EXTRACT(ISODOW FROM hourly.bucket AT TIME ZONE context.timezone)::int AS local_dow,
                    EXTRACT(HOUR FROM hourly.bucket AT TIME ZONE context.timezone)::int AS local_hour,
                    EXTRACT(ISODOW FROM context.local_now)::int AS current_dow,
                    EXTRACT(HOUR FROM context.local_now)::int AS current_hour
                FROM metric_samples_hourly AS hourly
                INNER JOIN servers ON servers.id = hourly.server_id
                INNER JOIN metric_names ON metric_names.id = hourly.metric_id
                LEFT JOIN metric_thresholds AS thresholds
                  ON thresholds.server_id = hourly.server_id
                 AND thresholds.metric_id = hourly.metric_id
                CROSS JOIN context
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
            ),
            scoped AS (
                SELECT
                    base.*,
                    LEAST(
                        ABS(local_hour - current_hour),
                        24 - ABS(local_hour - current_hour)
                    ) AS hour_distance
                FROM base
            ),
            stats AS (
                SELECT
                    server_id, server_name, metric_id, metric_name, warning_threshold,
                    'weekly_hour'::text AS context_name, 1 AS context_priority, timezone,
                    current_dow, current_hour,
                    percentile_cont(0.10) WITHIN GROUP (ORDER BY min_value) AS p10,
                    percentile_cont(0.50) WITHIN GROUP (ORDER BY avg_value) AS median,
                    percentile_cont(0.90) WITHIN GROUP (ORDER BY max_value) AS p90,
                    count(*) AS points,
                    count(DISTINCT date_trunc('week', bucket AT TIME ZONE timezone)) AS weeks,
                    min(bucket) AS first_at,
                    max(bucket) AS last_at
                FROM scoped
                WHERE local_dow = current_dow AND hour_distance <= 1
                GROUP BY server_id, server_name, metric_id, metric_name,
                         warning_threshold, timezone, current_dow, current_hour

                UNION ALL

                SELECT
                    server_id, server_name, metric_id, metric_name, warning_threshold,
                    'day_type_hour'::text, 2, timezone, current_dow, current_hour,
                    percentile_cont(0.10) WITHIN GROUP (ORDER BY min_value),
                    percentile_cont(0.50) WITHIN GROUP (ORDER BY avg_value),
                    percentile_cont(0.90) WITHIN GROUP (ORDER BY max_value),
                    count(*),
                    count(DISTINCT date_trunc('week', bucket AT TIME ZONE timezone)),
                    min(bucket),
                    max(bucket)
                FROM scoped
                WHERE (local_dow >= 6) = (current_dow >= 6) AND hour_distance <= 1
                GROUP BY server_id, server_name, metric_id, metric_name,
                         warning_threshold, timezone, current_dow, current_hour

                UNION ALL

                SELECT
                    server_id, server_name, metric_id, metric_name, warning_threshold,
                    'hour_of_day'::text, 3, timezone, current_dow, current_hour,
                    percentile_cont(0.10) WITHIN GROUP (ORDER BY min_value),
                    percentile_cont(0.50) WITHIN GROUP (ORDER BY avg_value),
                    percentile_cont(0.90) WITHIN GROUP (ORDER BY max_value),
                    count(*),
                    count(DISTINCT date_trunc('week', bucket AT TIME ZONE timezone)),
                    min(bucket),
                    max(bucket)
                FROM scoped
                WHERE hour_distance <= 1
                GROUP BY server_id, server_name, metric_id, metric_name,
                         warning_threshold, timezone, current_dow, current_hour
            )
            SELECT *
            FROM stats
            ORDER BY server_id, metric_id, context_priority
            SQL
        );
        $statement->execute([
            'timezone' => $this->timezone,
            'now' => $this->timestamp($now),
            'baseline_start' => $this->timestamp($now->modify('-56 days')),
            'baseline_end' => $this->timestamp($now->modify('-2 hours')),
        ]);

        $selected = [];
        foreach ($statement->fetchAll() as $row) {
            $context = (string) $row['context_name'];
            $points = (int) $row['points'];
            $weeks = (int) $row['weeks'];
            $ready = match ($context) {
                'weekly_hour' => $points >= 9 && $weeks >= 4,
                'day_type_hour' => $points >= 12 && $weeks >= 2,
                'hour_of_day' => $points >= 18 && $weeks >= 1,
                default => false,
            };
            $key = (int) $row['server_id'] . ':' . (int) $row['metric_id'];
            if (!$ready || isset($selected[$key])) {
                continue;
            }
            $selected[$key] = [
                'server_id' => (int) $row['server_id'],
                'server_name' => (string) $row['server_name'],
                'metric_id' => (int) $row['metric_id'],
                'metric_name' => (string) $row['metric_name'],
                'warning_threshold' => (float) $row['warning_threshold'],
                'p10' => (float) $row['p10'],
                'median' => (float) $row['median'],
                'p90' => (float) $row['p90'],
                'points' => $points,
                'weeks' => $weeks,
                'first_at' => (string) $row['first_at'],
                'last_at' => (string) $row['last_at'],
                'context' => $context,
                'timezone' => (string) $row['timezone'],
                'local_weekday' => (int) $row['current_dow'],
                'local_hour' => (int) $row['current_hour'],
            ];
        }

        return array_values($selected);
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

    /** @return list<array<string,mixed>> */
    public function approvedLevelPatterns(): array
    {
        $statement = $this->pdo->query(
            "SELECT server_id, metric_id, detector, fingerprint, current_value, details
             FROM observations
             WHERE kind = 'anomaly'
               AND status = 'accepted_normal'
               AND detector IN ('level_shift_v1', 'level_shift_v2')
             ORDER BY accepted_at DESC NULLS LAST, id DESC"
        );
        if ($statement === false) {
            return [];
        }

        return array_map(
            fn (array $row): array => [
                'server_id' => (int) $row['server_id'],
                'metric_id' => (int) $row['metric_id'],
                'detector' => (string) $row['detector'],
                'fingerprint' => (string) $row['fingerprint'],
                'current_value' => $row['current_value'] === null ? null : (float) $row['current_value'],
                'details' => $this->decodeDetails($row['details'] ?? null),
            ],
            $statement->fetchAll()
        );
    }

    /** @return list<array<string,mixed>> */
    public function openLevelEpisodes(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, server_id, metric_id, detector, status, current_value,
                    baseline_value, details, first_seen_at, last_seen_at
             FROM observations
             WHERE kind = 'anomaly'
               AND detector = 'level_shift_v2'
               AND status IN ('active', 'handled')"
        );
        if ($statement === false) {
            return [];
        }

        return array_map(
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'server_id' => (int) $row['server_id'],
                'metric_id' => (int) $row['metric_id'],
                'detector' => (string) $row['detector'],
                'status' => (string) $row['status'],
                'current_value' => $row['current_value'] === null ? null : (float) $row['current_value'],
                'baseline_value' => $row['baseline_value'] === null ? null : (float) $row['baseline_value'],
                'details' => $this->decodeDetails($row['details'] ?? null),
                'first_seen_at' => (string) $row['first_seen_at'],
                'last_seen_at' => (string) $row['last_seen_at'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return list<array{
     *   server_id:int,server_name:string,metric_id:int,metric_name:string,
     *   warning_threshold:float,current_value:float,total_gb:?float,time:string,value:float
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
                total_values.value AS total_gb,
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
            LEFT JOIN metric_names AS total_metric_names
              ON total_metric_names.name = 'disk_total_gb_' || substring(metric_names.name FROM 11)
            LEFT JOIN current_metric_values AS total_values
              ON total_values.server_id = hourly.server_id
             AND total_values.metric_id = total_metric_names.id
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
                'total_gb' => $row['total_gb'] === null ? null : (float) $row['total_gb'],
                'time' => (string) $row['bucket'],
                'value' => (float) $row['value'],
            ],
            $statement->fetchAll()
        );
    }

    /** @return array<string,mixed> */
    private function decodeDetails(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function timestamp(DateTimeInterface $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }
}
