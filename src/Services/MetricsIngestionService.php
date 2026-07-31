<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Metrics\AgentAuthenticationException;
use App\Domain\Metrics\MetricsEnvelope;
use App\Repositories\NotificationOutboxRepository;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class MetricsIngestionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ThresholdEvaluator $thresholdEvaluator,
        private readonly NotificationOutboxRepository $outbox
    ) {
    }

    public function ingest(MetricsEnvelope $envelope): MetricsIngestionResult
    {
        $ownsTransaction = $this->beginTransaction();

        try {
            $server = $this->authenticate($envelope->token);
            if (!$this->recordSample($server['id'], $envelope)) {
                $this->commitTransaction($ownsTransaction);
                return new MetricsIngestionResult($server['id'], true);
            }

            $metricIds = $this->ensureMetricIds($envelope->metrics);
            $this->insertHistory($server['id'], $envelope, $metricIds);
            $currentMetricIds = $this->upsertCurrentMetrics(
                $server['id'],
                $envelope,
                $metricIds
            );
            $this->updateServerAndToken($server['id'], $envelope);
            $this->storeProcessSnapshot($server['id'], $envelope);
            $this->evaluateMetricAlerts(
                $server,
                $envelope,
                $metricIds,
                $currentMetricIds
            );
            $this->updateServices($server, $envelope);

            $this->commitTransaction($ownsTransaction);
            return new MetricsIngestionResult($server['id'], false);
        } catch (Throwable $exception) {
            $this->rollbackTransaction($ownsTransaction);
            throw $exception;
        }
    }

    /** @return array{id: int, name: string} */
    private function authenticate(string $token): array
    {
        $statement = $this->pdo->prepare(
            'SELECT servers.id, servers.name
             FROM agent_tokens
             INNER JOIN servers ON servers.id = agent_tokens.server_id
             LEFT JOIN agent_configs ON agent_configs.server_id = servers.id
             WHERE agent_tokens.token_hash = :token_hash
               AND servers.is_active = TRUE
               AND COALESCE(agent_configs.enabled, TRUE) = TRUE
             FOR UPDATE OF agent_tokens'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $server = $statement->fetch();

        if (!is_array($server)) {
            throw new AgentAuthenticationException('Invalid agent token.');
        }

        return [
            'id' => (int) $server['id'],
            'name' => (string) $server['name'],
        ];
    }

    private function recordSample(int $serverId, MetricsEnvelope $envelope): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ingested_samples (server_id, sample_id, sample_time)
             VALUES (:server_id, :sample_id, :sample_time)
             ON CONFLICT (server_id, sample_id) DO NOTHING
             RETURNING sample_id'
        );
        $statement->execute([
            'server_id' => $serverId,
            'sample_id' => $envelope->sampleId,
            'sample_time' => $this->timestamp($envelope->sampleTime),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, float> $metrics
     * @return array<string, int>
     */
    private function ensureMetricIds(array $metrics): array
    {
        $insertRows = [];
        $insertParameters = [];
        $lookupParameters = [];
        $lookupPlaceholders = [];

        foreach (array_keys($metrics) as $index => $name) {
            $insertRows[] = sprintf(
                '(:name_%1$d, :unit_%1$d, :description_%1$d)',
                $index
            );
            $insertParameters['name_' . $index] = $name;
            $insertParameters['unit_' . $index] = $this->metricUnit($name);
            $insertParameters['description_' . $index] = $name;
            $lookupPlaceholders[] = ':lookup_' . $index;
            $lookupParameters['lookup_' . $index] = $name;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO metric_names (name, unit, description)
             VALUES ' . implode(', ', $insertRows) . '
             ON CONFLICT (name) DO NOTHING'
        );
        $insert->execute($insertParameters);

        $lookup = $this->pdo->prepare(
            'SELECT id, name
             FROM metric_names
             WHERE name IN (' . implode(', ', $lookupPlaceholders) . ')'
        );
        $lookup->execute($lookupParameters);

        $metricIds = [];
        foreach ($lookup->fetchAll() as $row) {
            $metricIds[(string) $row['name']] = (int) $row['id'];
        }

        if (count($metricIds) !== count($metrics)) {
            throw new RuntimeException('Cannot resolve metric names.');
        }

        return $metricIds;
    }

    /** @param array<string, int> $metricIds */
    private function insertHistory(
        int $serverId,
        MetricsEnvelope $envelope,
        array $metricIds
    ): void {
        $rows = [];
        $parameters = [
            'sample_time' => $this->timestamp($envelope->sampleTime),
            'server_id' => $serverId,
            'sample_id' => $envelope->sampleId,
        ];

        foreach ($envelope->metrics as $name => $value) {
            $index = count($rows);
            $rows[] = sprintf(
                '(:sample_time, :server_id, :metric_id_%1$d, :sample_id, :value_%1$d)',
                $index
            );
            $parameters['metric_id_' . $index] = $metricIds[$name];
            $parameters['value_' . $index] = $value;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO metric_samples (
                sample_time,
                server_id,
                metric_id,
                sample_id,
                value
             ) VALUES ' . implode(', ', $rows)
        );
        $statement->execute($parameters);
    }

    /**
     * @param array<string, int> $metricIds
     * @return list<int>
     */
    private function upsertCurrentMetrics(
        int $serverId,
        MetricsEnvelope $envelope,
        array $metricIds
    ): array {
        $rows = [];
        $parameters = [
            'sample_time' => $this->timestamp($envelope->sampleTime),
            'server_id' => $serverId,
            'sample_id' => $envelope->sampleId,
        ];

        foreach ($envelope->metrics as $name => $value) {
            $index = count($rows);
            $rows[] = sprintf(
                '(:server_id, :metric_id_%1$d, :sample_time, :sample_id, :value_%1$d)',
                $index
            );
            $parameters['metric_id_' . $index] = $metricIds[$name];
            $parameters['value_' . $index] = $value;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO current_metric_values (
                server_id,
                metric_id,
                sample_time,
                sample_id,
                value
             ) VALUES ' . implode(', ', $rows) . '
             ON CONFLICT (server_id, metric_id) DO UPDATE SET
                sample_time = EXCLUDED.sample_time,
                sample_id = EXCLUDED.sample_id,
                value = EXCLUDED.value
             WHERE (EXCLUDED.sample_time, EXCLUDED.sample_id)
                > (
                    current_metric_values.sample_time,
                    current_metric_values.sample_id
                )
             RETURNING metric_id'
        );
        $statement->execute($parameters);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function updateServerAndToken(
        int $serverId,
        MetricsEnvelope $envelope
    ): void {
        $timestamp = $this->timestamp($envelope->sampleTime);
        $server = $this->pdo->prepare(
            'UPDATE servers
             SET last_metrics_at = GREATEST(
                    COALESCE(last_metrics_at, :sample_time),
                    :sample_time
                 ),
                 agent_version = COALESCE(:agent_version, agent_version)
             WHERE id = :server_id'
        );
        $server->execute([
            'server_id' => $serverId,
            'sample_time' => $timestamp,
            'agent_version' => $envelope->agentVersion,
        ]);

        $token = $this->pdo->prepare(
            'UPDATE agent_tokens
             SET last_used_at = CURRENT_TIMESTAMP
             WHERE server_id = :server_id'
        );
        $token->execute(['server_id' => $serverId]);
    }

    private function storeProcessSnapshot(
        int $serverId,
        MetricsEnvelope $envelope
    ): void {
        if ($envelope->processSnapshot === null) {
            return;
        }

        try {
            $processes = json_encode(
                $envelope->processSnapshot,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode process snapshot.', 0, $exception);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO process_snapshots (
                sample_time,
                server_id,
                sample_id,
                processes
             ) VALUES (
                :sample_time,
                :server_id,
                :sample_id,
                CAST(:processes AS jsonb)
             )'
        );
        $statement->execute([
            'sample_time' => $this->timestamp($envelope->sampleTime),
            'server_id' => $serverId,
            'sample_id' => $envelope->sampleId,
            'processes' => $processes,
        ]);
    }

    /**
     * @param array{id: int, name: string} $server
     * @param array<string, int> $metricIds
     * @param list<int> $currentMetricIds
     */
    private function evaluateMetricAlerts(
        array $server,
        MetricsEnvelope $envelope,
        array $metricIds,
        array $currentMetricIds
    ): void {
        if ($currentMetricIds === []) {
            return;
        }

        $placeholders = [];
        $parameters = ['server_id' => $server['id']];
        foreach ($currentMetricIds as $index => $metricId) {
            $placeholders[] = ':metric_' . $index;
            $parameters['metric_' . $index] = $metricId;
        }

        $statement = $this->pdo->prepare(
            'SELECT
                metric_id,
                warning_threshold,
                critical_threshold,
                duration_seconds,
                recovery_duration_seconds
             FROM metric_thresholds
             WHERE server_id = :server_id
               AND metric_id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($parameters);
        $thresholds = [];
        foreach ($statement->fetchAll() as $row) {
            $thresholds[(int) $row['metric_id']] = $row;
        }

        $namesById = array_flip($metricIds);
        foreach ($currentMetricIds as $metricId) {
            $metricName = (string) $namesById[$metricId];
            $value = $envelope->metrics[$metricName];
            $threshold = $thresholds[$metricId] ?? null;
            $warning = $threshold === null || $threshold['warning_threshold'] === null
                ? null
                : (float) $threshold['warning_threshold'];
            $critical = $threshold === null || $threshold['critical_threshold'] === null
                ? null
                : (float) $threshold['critical_threshold'];
            $severity = $this->thresholdEvaluator->severity($value, $warning, $critical);

            $active = $this->activeAlert($server['id'], 'metric', $metricId, null);
            if ($severity === null) {
                if (
                    $active !== null
                    && $this->metricRecovered(
                        $server['id'],
                        $metricId,
                        $warning ?? $critical,
                        (int) ($threshold['recovery_duration_seconds'] ?? 0),
                        $envelope->sampleTime
                    )
                ) {
                    $this->resolveAlert(
                        $active,
                        $server,
                        $metricName,
                        $value,
                        $envelope
                    );
                }
                continue;
            }

            $duration = (int) ($threshold['duration_seconds'] ?? 0);
            $thresholdValue = $severity === 'critical' ? $critical : $warning;
            if (
                $active === null
                && (
                    $thresholdValue === null
                    || !$this->thresholdHeldForDuration(
                        $server['id'],
                        $metricId,
                        $thresholdValue,
                        $duration,
                        $envelope->sampleTime
                    )
                )
            ) {
                continue;
            }

            if ($active === null) {
                $this->createMetricAlert(
                    $server,
                    $metricId,
                    $metricName,
                    $value,
                    $severity,
                    $envelope
                );
                continue;
            }

            $previousSeverity = (string) $active['severity'];
            $update = $this->pdo->prepare(
                'UPDATE alerts
                 SET value = :value, severity = :severity
                 WHERE id = :id'
            );
            $update->execute([
                'id' => (int) $active['id'],
                'value' => $value,
                'severity' => $severity,
            ]);

            if ($previousSeverity === 'warning' && $severity === 'critical') {
                $payload = $this->metricPayload(
                    'escalated',
                    $server,
                    $metricName,
                    $value,
                    $severity,
                    $envelope
                );
                $this->outbox->enqueueConfigured(
                    $server['id'],
                    (int) $active['id'],
                    'metric_escalated',
                    $payload,
                    $this->deduplicationKey(
                        $server['id'],
                        (int) $active['id'],
                        'metric_escalated',
                        $envelope->sampleId
                    )
                );
            }
        }
    }

    /**
     * @param array{id: int, name: string} $server
     */
    private function updateServices(array $server, MetricsEnvelope $envelope): void
    {
        if ($envelope->services === []) {
            return;
        }

        $monitored = $this->monitoredServices($server['id']);
        foreach ($envelope->services as $service) {
            $previous = $this->serviceStatus($server['id'], $service['name']);
            if (
                $previous !== null
                && new DateTimeImmutable((string) $previous['updated_at']) >= $envelope->sampleTime
            ) {
                continue;
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO service_status (
                    server_id,
                    service_name,
                    status,
                    load_state,
                    active_state,
                    sub_state,
                    updated_at
                 ) VALUES (
                    :server_id,
                    :service_name,
                    :status,
                    :load_state,
                    :active_state,
                    :sub_state,
                    :updated_at
                 )
                 ON CONFLICT (server_id, service_name) DO UPDATE SET
                    status = EXCLUDED.status,
                    load_state = EXCLUDED.load_state,
                    active_state = EXCLUDED.active_state,
                    sub_state = EXCLUDED.sub_state,
                    updated_at = EXCLUDED.updated_at
                 WHERE EXCLUDED.updated_at > service_status.updated_at'
            );
            $statement->execute([
                'server_id' => $server['id'],
                'service_name' => $service['name'],
                'status' => $service['status'],
                'load_state' => $service['load_state'],
                'active_state' => $service['active_state'],
                'sub_state' => $service['sub_state'],
                'updated_at' => $this->timestamp($envelope->sampleTime),
            ]);

            if (!in_array($service['name'], $monitored, true)) {
                continue;
            }
            if ($previous !== null && $previous['status'] === $service['status']) {
                continue;
            }

            $active = $this->activeAlert(
                $server['id'],
                'service',
                null,
                $service['name']
            );
            if ($service['status'] === 'running') {
                if ($active !== null) {
                    $this->resolveServiceAlert(
                        $active,
                        $server,
                        $service['name'],
                        $envelope
                    );
                }
                continue;
            }

            $severity = $service['status'] === 'stopped' ? 'critical' : 'warning';
            if ($active === null) {
                $this->createServiceAlert(
                    $server,
                    $service['name'],
                    $service['status'],
                    $severity,
                    $envelope
                );
                continue;
            }

            $update = $this->pdo->prepare(
                'UPDATE alerts SET severity = :severity WHERE id = :id'
            );
            $update->execute([
                'id' => (int) $active['id'],
                'severity' => $severity,
            ]);
        }

        $statement = $this->pdo->prepare(
            'UPDATE servers
             SET last_service_check_at = GREATEST(
                    COALESCE(last_service_check_at, :sample_time),
                    :sample_time
                 )
             WHERE id = :server_id'
        );
        $statement->execute([
            'server_id' => $server['id'],
            'sample_time' => $this->timestamp($envelope->sampleTime),
        ]);
    }

    /** @return list<string> */
    private function monitoredServices(int $serverId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT monitor_services
             FROM agent_configs
             WHERE server_id = :server_id'
        );
        $statement->execute(['server_id' => $serverId]);
        $encoded = $statement->fetchColumn();
        if ($encoded === false) {
            return [];
        }

        try {
            $services = json_decode((string) $encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($services)
            ? array_values(array_filter($services, 'is_string'))
            : [];
    }

    /** @return array<string, mixed>|null */
    private function serviceStatus(int $serverId, string $serviceName): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, updated_at
             FROM service_status
             WHERE server_id = :server_id AND service_name = :service_name
             FOR UPDATE'
        );
        $statement->execute([
            'server_id' => $serverId,
            'service_name' => $serviceName,
        ]);
        $status = $statement->fetch();

        return is_array($status) ? $status : null;
    }

    /** @return array<string, mixed>|null */
    private function activeAlert(
        int $serverId,
        string $kind,
        ?int $metricId,
        ?string $subject
    ): ?array {
        if ($kind === 'metric') {
            $identityClause = 'metric_id = :identity';
            $identity = $metricId;
        } elseif ($kind === 'service') {
            $identityClause = 'subject = :identity';
            $identity = $subject;
        } else {
            throw new RuntimeException('Unsupported alert kind.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id, severity, value
             FROM alerts
             WHERE server_id = :server_id
               AND kind = :kind
               AND ' . $identityClause . '
               AND resolved = FALSE
             FOR UPDATE'
        );
        $statement->execute([
            'server_id' => $serverId,
            'kind' => $kind,
            'identity' => $identity,
        ]);
        $alert = $statement->fetch();

        return is_array($alert) ? $alert : null;
    }

    /** @param array{id: int, name: string} $server */
    private function createMetricAlert(
        array $server,
        int $metricId,
        string $metricName,
        float $value,
        string $severity,
        MetricsEnvelope $envelope
    ): void {
        $statement = $this->pdo->prepare(
            "INSERT INTO alerts (
                server_id,
                metric_id,
                kind,
                subject,
                value,
                severity
             ) VALUES (
                :server_id,
                :metric_id,
                'metric',
                :subject,
                :value,
                :severity
             )
             RETURNING id"
        );
        $statement->execute([
            'server_id' => $server['id'],
            'metric_id' => $metricId,
            'subject' => $metricName,
            'value' => $value,
            'severity' => $severity,
        ]);
        $alertId = (int) $statement->fetchColumn();
        $payload = $this->metricPayload(
            'triggered',
            $server,
            $metricName,
            $value,
            $severity,
            $envelope
        );
        $this->outbox->enqueueConfigured(
            $server['id'],
            $alertId,
            'metric_triggered',
            $payload,
            $this->deduplicationKey(
                $server['id'],
                $alertId,
                'metric_triggered',
                $envelope->sampleId
            )
        );
    }

    /**
     * @param array<string, mixed> $active
     * @param array{id: int, name: string} $server
     */
    private function resolveAlert(
        array $active,
        array $server,
        string $metricName,
        float $value,
        MetricsEnvelope $envelope
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE alerts
             SET
                value = :value,
                resolved = TRUE,
                resolved_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => (int) $active['id'],
            'value' => $value,
        ]);
        $payload = $this->metricPayload(
            'recovered',
            $server,
            $metricName,
            $value,
            (string) $active['severity'],
            $envelope
        );
        $this->outbox->enqueueConfigured(
            $server['id'],
            (int) $active['id'],
            'metric_recovered',
            $payload,
            $this->deduplicationKey(
                $server['id'],
                (int) $active['id'],
                'metric_recovered',
                $envelope->sampleId
            )
        );
    }

    /** @param array{id: int, name: string} $server */
    private function createServiceAlert(
        array $server,
        string $serviceName,
        string $status,
        string $severity,
        MetricsEnvelope $envelope
    ): void {
        $statement = $this->pdo->prepare(
            "INSERT INTO alerts (
                server_id,
                kind,
                subject,
                severity
             ) VALUES (
                :server_id,
                'service',
                :subject,
                :severity
             )
             RETURNING id"
        );
        $statement->execute([
            'server_id' => $server['id'],
            'subject' => $serviceName,
            'severity' => $severity,
        ]);
        $alertId = (int) $statement->fetchColumn();
        $payload = $this->servicePayload(
            'triggered',
            $server,
            $serviceName,
            $status,
            $severity,
            $envelope
        );
        $this->outbox->enqueueConfigured(
            $server['id'],
            $alertId,
            'service_triggered',
            $payload,
            $this->deduplicationKey(
                $server['id'],
                $alertId,
                'service_triggered',
                $envelope->sampleId
            )
        );
    }

    /**
     * @param array<string, mixed> $active
     * @param array{id: int, name: string} $server
     */
    private function resolveServiceAlert(
        array $active,
        array $server,
        string $serviceName,
        MetricsEnvelope $envelope
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE alerts
             SET resolved = TRUE, resolved_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['id' => (int) $active['id']]);
        $payload = $this->servicePayload(
            'recovered',
            $server,
            $serviceName,
            'running',
            (string) $active['severity'],
            $envelope
        );
        $this->outbox->enqueueConfigured(
            $server['id'],
            (int) $active['id'],
            'service_recovered',
            $payload,
            $this->deduplicationKey(
                $server['id'],
                (int) $active['id'],
                'service_recovered',
                $envelope->sampleId
            )
        );
    }

    private function thresholdHeldForDuration(
        int $serverId,
        int $metricId,
        float $threshold,
        int $durationSeconds,
        DateTimeImmutable $sampleTime
    ): bool {
        if ($durationSeconds <= 0) {
            return true;
        }

        $windowStart = $sampleTime->modify('-' . $durationSeconds . ' seconds');
        $statement = $this->pdo->prepare(
            'SELECT
                COALESCE((
                    SELECT value >= :boundary_threshold
                    FROM metric_samples
                    WHERE server_id = :server_id
                      AND metric_id = :metric_id
                      AND sample_time <= :window_start
                    ORDER BY sample_time DESC
                    LIMIT 1
                ), FALSE)
                AND NOT EXISTS (
                    SELECT 1
                    FROM metric_samples
                    WHERE server_id = :server_id_window
                      AND metric_id = :metric_id_window
                      AND sample_time > :window_start_window
                      AND sample_time <= :sample_time
                      AND value < :window_threshold
                )'
        );
        $statement->execute([
            'boundary_threshold' => $threshold,
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'window_start' => $this->timestamp($windowStart),
            'server_id_window' => $serverId,
            'metric_id_window' => $metricId,
            'window_start_window' => $this->timestamp($windowStart),
            'sample_time' => $this->timestamp($sampleTime),
            'window_threshold' => $threshold,
        ]);

        return $this->toBool($statement->fetchColumn());
    }

    /**
     * Mirrors thresholdHeldForDuration() for the way back: an alert closes
     * only after the metric has stayed under the lowest configured threshold
     * for the whole recovery window, so a value oscillating around the
     * threshold does not produce an alert-recovery-alert stream.
     */
    private function metricRecovered(
        int $serverId,
        int $metricId,
        ?float $threshold,
        int $recoverySeconds,
        DateTimeImmutable $sampleTime
    ): bool {
        if ($threshold === null || $recoverySeconds <= 0) {
            return true;
        }

        $windowStart = $sampleTime->modify('-' . $recoverySeconds . ' seconds');
        $statement = $this->pdo->prepare(
            'SELECT
                COALESCE((
                    SELECT value < :boundary_threshold
                    FROM metric_samples
                    WHERE server_id = :server_id
                      AND metric_id = :metric_id
                      AND sample_time <= :window_start
                    ORDER BY sample_time DESC
                    LIMIT 1
                ), FALSE)
                AND NOT EXISTS (
                    SELECT 1
                    FROM metric_samples
                    WHERE server_id = :server_id_window
                      AND metric_id = :metric_id_window
                      AND sample_time > :window_start_window
                      AND sample_time <= :sample_time
                      AND value >= :window_threshold
                )'
        );
        $statement->execute([
            'boundary_threshold' => $threshold,
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'window_start' => $this->timestamp($windowStart),
            'server_id_window' => $serverId,
            'metric_id_window' => $metricId,
            'window_start_window' => $this->timestamp($windowStart),
            'sample_time' => $this->timestamp($sampleTime),
            'window_threshold' => $threshold,
        ]);

        return $this->toBool($statement->fetchColumn());
    }

    /**
     * @param array{id: int, name: string} $server
     * @return array<string, mixed>
     */
    private function metricPayload(
        string $event,
        array $server,
        string $metricName,
        float $value,
        string $severity,
        MetricsEnvelope $envelope
    ): array {
        return [
            'type' => 'metric',
            'event' => $event,
            'server_id' => $server['id'],
            'server_name' => $server['name'],
            'metric' => $metricName,
            'value' => $value,
            'severity' => $severity,
            'sample_time' => $envelope->sampleTime->format(DATE_ATOM),
        ];
    }

    /**
     * @param array{id: int, name: string} $server
     * @return array<string, mixed>
     */
    private function servicePayload(
        string $event,
        array $server,
        string $serviceName,
        string $status,
        string $severity,
        MetricsEnvelope $envelope
    ): array {
        return [
            'type' => 'service',
            'event' => $event,
            'server_id' => $server['id'],
            'server_name' => $server['name'],
            'service' => $serviceName,
            'status' => $status,
            'severity' => $severity,
            'sample_time' => $envelope->sampleTime->format(DATE_ATOM),
        ];
    }

    private function deduplicationKey(
        int $serverId,
        int $alertId,
        string $eventType,
        string $sampleId
    ): string {
        return implode(':', [$serverId, $alertId, $eventType, $sampleId]);
    }

    private function metricUnit(string $name): ?string
    {
        if (
            $name === 'cpu_load'
            || $name === 'ram_used'
            || $name === 'disk_used'
        ) {
            return '%';
        }
        if (str_contains($name, 'total_gb')) {
            return 'GB';
        }
        if (str_starts_with($name, 'temp_')) {
            return '°C';
        }
        if (str_starts_with($name, 'net_') || str_starts_with($name, 'network_')) {
            return 'B/s';
        }
        if ($name === 'uptime') {
            return 's';
        }

        return null;
    }

    private function timestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->format('Y-m-d H:i:s.uP');
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function beginTransaction(): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            return true;
        }

        $this->pdo->exec('SAVEPOINT metrics_ingestion');
        return false;
    }

    private function commitTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            $this->pdo->commit();
            return;
        }

        $this->pdo->exec('RELEASE SAVEPOINT metrics_ingestion');
    }

    private function rollbackTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return;
        }

        $this->pdo->exec('ROLLBACK TO SAVEPOINT metrics_ingestion');
        $this->pdo->exec('RELEASE SAVEPOINT metrics_ingestion');
    }
}
