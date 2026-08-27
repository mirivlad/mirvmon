<?php

declare(strict_types=1);

namespace App\Repositories;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class ServerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Load every dashboard card with its newest metric values, thresholds, and
     * unresolved alert counts in one set-based query.
     *
     * @return list<array<string, mixed>>
     */
    public function dashboardServers(): array
    {
        $statement = $this->pdo->query(
            <<<'SQL'
            WITH newest_samples AS (
                SELECT
                    current_values.server_id,
                    current_values.metric_id,
                    current_values.sample_time,
                    current_values.sample_id,
                    current_values.value
                FROM current_metric_values AS current_values
            ),
            latest_metrics AS (
                SELECT
                    samples.server_id,
                    jsonb_object_agg(
                        names.name,
                        jsonb_build_object(
                            'value', samples.value,
                            'unit', names.unit,
                            'sample_time', samples.sample_time
                        )
                        ORDER BY names.name
                    ) AS metrics
                FROM newest_samples AS samples
                INNER JOIN metric_names AS names ON names.id = samples.metric_id
                GROUP BY samples.server_id
            ),
            active_alerts AS (
                SELECT
                    alerts.server_id,
                    count(*) AS active_alerts,
                    count(*) FILTER (WHERE alerts.severity = 'warning') AS warning_alerts,
                    count(*) FILTER (WHERE alerts.severity = 'critical') AS critical_alerts
                FROM alerts
                WHERE alerts.resolved = FALSE
                GROUP BY alerts.server_id
            ),
            server_thresholds AS (
                SELECT
                    thresholds.server_id,
                    jsonb_object_agg(
                        names.name,
                        jsonb_build_object(
                            'warning', thresholds.warning_threshold,
                            'critical', thresholds.critical_threshold
                        )
                        ORDER BY names.name
                    ) AS thresholds
                FROM metric_thresholds AS thresholds
                INNER JOIN metric_names AS names ON names.id = thresholds.metric_id
                GROUP BY thresholds.server_id
            )
            SELECT
                servers.id,
                servers.name,
                servers.address,
                servers.description,
                servers.os_version,
                servers.agent_artifact,
                servers.group_id,
                servers.display_metrics,
                servers.is_active,
                servers.last_metrics_at,
                agent_tokens.last_used_at AS last_contact_at,
                servers.last_service_check_at,
                servers.offline_timeout_seconds,
                servers.notify_on_offline,
                servers.created_at,
                groups.name AS group_name,
                groups.icon AS group_icon,
                groups.color AS group_color,
                COALESCE(active_alerts.active_alerts, 0) AS active_alerts,
                COALESCE(active_alerts.warning_alerts, 0) AS warning_alerts,
                COALESCE(active_alerts.critical_alerts, 0) AS critical_alerts,
                COALESCE(latest_metrics.metrics, '{}'::jsonb) AS latest_metrics,
                COALESCE(server_thresholds.thresholds, '{}'::jsonb) AS thresholds
            FROM servers
            LEFT JOIN agent_tokens ON agent_tokens.server_id = servers.id
            LEFT JOIN monitoring_groups AS groups ON groups.id = servers.group_id
            LEFT JOIN latest_metrics ON latest_metrics.server_id = servers.id
            LEFT JOIN active_alerts ON active_alerts.server_id = servers.id
            LEFT JOIN server_thresholds ON server_thresholds.server_id = servers.id
            ORDER BY groups.sort_order, groups.name, servers.name, servers.id
            SQL
        );

        if ($statement === false) {
            throw new RuntimeException('Unable to load dashboard servers.');
        }

        return array_map(
            fn (array $row): array => $this->normalizeDashboardRow($row),
            $statement->fetchAll()
        );
    }

    public function groupCount(): int
    {
        $count = $this->pdo->query('SELECT count(*) FROM monitoring_groups')?->fetchColumn();

        return (int) $count;
    }

    /** @return array<string, mixed>|null */
    public function find(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                servers.*,
                agent_tokens.last_used_at AS last_contact_at,
                groups.name AS group_name,
                groups.icon AS group_icon,
                groups.color AS group_color,
                COALESCE(alert_counts.warning_alerts, 0) AS warning_alerts,
                COALESCE(alert_counts.critical_alerts, 0) AS critical_alerts,
                agent_tokens.last_used_at AS last_seen
            FROM servers
            LEFT JOIN agent_tokens ON agent_tokens.server_id = servers.id
            LEFT JOIN monitoring_groups AS groups ON groups.id = servers.group_id
            LEFT JOIN LATERAL (
                SELECT
                    count(*) FILTER (WHERE severity = 'warning') AS warning_alerts,
                    count(*) FILTER (WHERE severity = 'critical') AS critical_alerts
                FROM alerts
                WHERE alerts.server_id = servers.id
                  AND alerts.resolved = FALSE
            ) AS alert_counts ON TRUE
            WHERE servers.id = :server_id
            SQL
        );
        $statement->execute(['server_id' => $serverId]);
        $server = $statement->fetch();

        if (!is_array($server)) {
            return null;
        }

        $server['id'] = (int) $server['id'];
        $server['offline_timeout_seconds'] = (int) $server['offline_timeout_seconds'];
        $server['is_active'] = $this->toBool($server['is_active']);
        $server['notify_on_offline'] = $this->toBool($server['notify_on_offline']);
        $server['display_metrics'] = $this->decodeList($server['display_metrics'] ?? '[]');

        return $server;
    }

    /**
     * @return array<string, array{
     *     warning: ?float,
     *     critical: ?float,
     *     duration: int,
     *     recovery: int
     * }>
     */
    public function thresholds(int $serverId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                names.name,
                thresholds.warning_threshold,
                thresholds.critical_threshold,
                thresholds.duration_seconds,
                thresholds.recovery_duration_seconds
            FROM metric_thresholds AS thresholds
            INNER JOIN metric_names AS names ON names.id = thresholds.metric_id
            WHERE thresholds.server_id = :server_id
            ORDER BY names.name
            SQL
        );
        $statement->execute(['server_id' => $serverId]);

        $thresholds = [];
        foreach ($statement->fetchAll() as $row) {
            $thresholds[$row['name']] = [
                'warning' => $row['warning_threshold'] === null
                    ? null
                    : (float) $row['warning_threshold'],
                'critical' => $row['critical_threshold'] === null
                    ? null
                    : (float) $row['critical_threshold'],
                'duration' => (int) $row['duration_seconds'],
                'recovery' => (int) $row['recovery_duration_seconds'],
            ];
        }

        return $thresholds;
    }

    /** @return list<array<string, mixed>> */
    public function services(int $serverId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT service_name, status, load_state, active_state, sub_state, updated_at
            FROM service_status
            WHERE server_id = :server_id
            ORDER BY service_name
            SQL
        );
        $statement->execute(['server_id' => $serverId]);

        return $statement->fetchAll();
    }

    /** @return list<string> */
    public function monitoredServices(int $serverId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT monitor_services FROM agent_configs WHERE server_id = :server_id'
        );
        $statement->execute(['server_id' => $serverId]);
        $value = $statement->fetchColumn();

        return $value === false ? [] : $this->decodeList($value);
    }

    /** @return array{warning: float, critical: float, duration: int, recovery: int} */
    public function thresholdDefaults(): array
    {
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT setting_key, setting_value
            FROM app_settings
            WHERE setting_key IN (
                'default_warning_threshold',
                'default_critical_threshold',
                'default_duration_seconds',
                'default_recovery_duration_seconds'
            )
            SQL
        );

        $values = [];
        foreach ($statement?->fetchAll() ?? [] as $row) {
            $values[$row['setting_key']] = json_decode(
                (string) $row['setting_value'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        return [
            'warning' => (float) ($values['default_warning_threshold'] ?? 70),
            'critical' => (float) ($values['default_critical_threshold'] ?? 90),
            'duration' => (int) ($values['default_duration_seconds'] ?? 0),
            'recovery' => (int) ($values['default_recovery_duration_seconds'] ?? 300),
        ];
    }

    /**
     * @param list<array{
     *     metric_id: int,
     *     warning: float,
     *     critical: float,
     *     duration: int,
     *     recovery: int
     * }> $thresholds
     */
    public function replaceThresholds(int $serverId, array $thresholds): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM metric_thresholds WHERE server_id = :server_id'
            );
            $delete->execute(['server_id' => $serverId]);

            $insert = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO metric_thresholds (
                    server_id,
                    metric_id,
                    warning_threshold,
                    critical_threshold,
                    duration_seconds,
                    recovery_duration_seconds
                )
                VALUES (
                    :server_id,
                    :metric_id,
                    :warning_threshold,
                    :critical_threshold,
                    :duration_seconds,
                    :recovery_duration_seconds
                )
                SQL
            );
            foreach ($thresholds as $threshold) {
                $insert->execute([
                    'server_id' => $serverId,
                    'metric_id' => $threshold['metric_id'],
                    'warning_threshold' => $threshold['warning'],
                    'critical_threshold' => $threshold['critical'],
                    'duration_seconds' => $threshold['duration'],
                    'recovery_duration_seconds' => $threshold['recovery'],
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param list<string> $services */
    public function saveMonitoredServices(int $serverId, array $services): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO agent_configs (server_id, interval_seconds, monitor_services, enabled)
            VALUES (:server_id, 60, CAST(:services AS jsonb), TRUE)
            ON CONFLICT (server_id) DO UPDATE
            SET
                monitor_services = EXCLUDED.monitor_services,
                updated_at = CURRENT_TIMESTAMP
            SQL
        );
        $statement->execute([
            'server_id' => $serverId,
            'services' => json_encode(
                $services,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeDashboardRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['group_id'] = $row['group_id'] === null ? null : (int) $row['group_id'];
        $row['is_active'] = $this->toBool($row['is_active']);
        $row['notify_on_offline'] = $this->toBool($row['notify_on_offline']);
        $row['offline_timeout_seconds'] = (int) $row['offline_timeout_seconds'];
        $row['active_alerts'] = (int) $row['active_alerts'];
        $row['warning_alerts'] = (int) $row['warning_alerts'];
        $row['critical_alerts'] = (int) $row['critical_alerts'];
        $row['display_metrics'] = $this->decodeList($row['display_metrics'] ?? '[]');
        $row['latest_metrics'] = $this->normalizeMetrics(
            $this->decodeObject($row['latest_metrics'] ?? '{}')
        );
        $row['thresholds'] = $this->normalizeThresholds(
            $this->decodeObject($row['thresholds'] ?? '{}')
        );

        return $row;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, array{value: float, unit: ?string, sample_time: ?string}>
     */
    private function normalizeMetrics(array $metrics): array
    {
        $normalized = [];
        foreach ($metrics as $name => $metric) {
            if (!is_array($metric) || !isset($metric['value'])) {
                continue;
            }

            $normalized[$name] = [
                'value' => (float) $metric['value'],
                'unit' => isset($metric['unit']) ? (string) $metric['unit'] : null,
                'sample_time' => isset($metric['sample_time'])
                    ? (string) $metric['sample_time']
                    : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $thresholds
     * @return array<string, array{warning: ?float, critical: ?float}>
     */
    private function normalizeThresholds(array $thresholds): array
    {
        $normalized = [];
        foreach ($thresholds as $name => $threshold) {
            if (!is_array($threshold)) {
                continue;
            }

            $normalized[$name] = [
                'warning' => isset($threshold['warning'])
                    ? (float) $threshold['warning']
                    : null,
                'critical' => isset($threshold['critical'])
                    ? (float) $threshold['critical']
                    : null,
            ];
        }

        return $normalized;
    }

    /** @return list<mixed> */
    private function decodeList(mixed $value): array
    {
        $decoded = $this->decodeJson($value);

        return array_is_list($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $value): array
    {
        $decoded = $this->decodeJson($value);

        return array_is_list($decoded) ? [] : $decoded;
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Database contains invalid JSON.', 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function toBool(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array($value, ['1', 't', 'true'], true);
    }
}
