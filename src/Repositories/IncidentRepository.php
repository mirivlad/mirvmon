<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class IncidentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{server_id?: int, group_id?: int, kind?: string, severity?: string, from?: string, to?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function active(array $filters = []): array
    {
        [$where, $parameters] = $this->alertFilters($filters, 'alerts.created_at');
        array_unshift($where, 'alerts.resolved = FALSE');

        $statement = $this->pdo->prepare(
            'SELECT
                alerts.id,
                alerts.server_id,
                alerts.kind,
                alerts.subject,
                alerts.value,
                alerts.severity,
                alerts.created_at,
                NULL::timestamptz AS resolved_at,
                NULL::varchar AS resolved_by_username,
                EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - alerts.created_at))::bigint AS duration_seconds,
                servers.name AS server_name,
                server_groups.id AS group_id,
                server_groups.name AS group_name,
                COALESCE(metric_names.name, alerts.subject, alerts.kind) AS subject_name,
                metric_names.unit
             FROM alerts
             INNER JOIN servers ON servers.id = alerts.server_id
             LEFT JOIN server_groups ON server_groups.id = servers.group_id
             LEFT JOIN metric_names ON metric_names.id = alerts.metric_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY
                CASE alerts.severity WHEN \'critical\' THEN 0 ELSE 1 END,
                alerts.created_at ASC,
                alerts.id ASC'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * Completed metric/service incidents plus completed offline periods from the
     * availability transition log. Offline alert rows are intentionally omitted
     * here so a single outage is not shown twice.
     *
     * @param array{server_id?: int, group_id?: int, kind?: string, severity?: string, from?: string, to?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function history(array $filters = []): array
    {
        $conditions = [];
        $parameters = [];

        if (isset($filters['server_id'])) {
            $conditions[] = 'events.server_id = :server_id';
            $parameters['server_id'] = $filters['server_id'];
        }
        if (isset($filters['group_id'])) {
            $conditions[] = 'events.group_id = :group_id';
            $parameters['group_id'] = $filters['group_id'];
        }
        if (isset($filters['kind'])) {
            $conditions[] = 'events.kind = :kind';
            $parameters['kind'] = $filters['kind'];
        }
        if (isset($filters['severity'])) {
            $conditions[] = 'events.severity = :severity';
            $parameters['severity'] = $filters['severity'];
        }
        if (isset($filters['from'])) {
            $conditions[] = 'events.created_at >= :from';
            $parameters['from'] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $conditions[] = 'events.created_at < :to';
            $parameters['to'] = $filters['to'];
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $statement = $this->pdo->prepare(
            <<<'SQL'
            WITH completed_outages AS (
                SELECT
                    availability.id,
                    availability.server_id,
                    availability.occurred_at AS created_at,
                    (
                        SELECT recovered.occurred_at
                        FROM server_availability_events AS recovered
                        WHERE recovered.server_id = availability.server_id
                          AND recovered.state = 'online'
                          AND (
                              recovered.occurred_at > availability.occurred_at
                              OR (
                                  recovered.occurred_at = availability.occurred_at
                                  AND recovered.id > availability.id
                              )
                          )
                        ORDER BY recovered.occurred_at ASC, recovered.id ASC
                        LIMIT 1
                    ) AS resolved_at
                FROM server_availability_events AS availability
                WHERE availability.state = 'offline'
            ),
            events AS (
                SELECT
                    'alert'::varchar AS source,
                    alerts.id AS source_id,
                    alerts.server_id,
                    alerts.kind,
                    alerts.severity,
                    alerts.value,
                    alerts.created_at,
                    alerts.resolved_at,
                    alerts.resolved_by_username,
                    EXTRACT(EPOCH FROM (alerts.resolved_at - alerts.created_at))::bigint AS duration_seconds,
                    servers.name AS server_name,
                    server_groups.id AS group_id,
                    server_groups.name AS group_name,
                    COALESCE(metric_names.name, alerts.subject, alerts.kind) AS subject_name,
                    metric_names.unit
                FROM alerts
                INNER JOIN servers ON servers.id = alerts.server_id
                LEFT JOIN server_groups ON server_groups.id = servers.group_id
                LEFT JOIN metric_names ON metric_names.id = alerts.metric_id
                WHERE alerts.resolved = TRUE
                  AND alerts.kind <> 'offline'

                UNION ALL

                SELECT
                    'availability'::varchar AS source,
                    completed_outages.id AS source_id,
                    completed_outages.server_id,
                    'offline'::varchar AS kind,
                    'critical'::varchar AS severity,
                    NULL::double precision AS value,
                    completed_outages.created_at,
                    completed_outages.resolved_at,
                    NULL::varchar AS resolved_by_username,
                    EXTRACT(EPOCH FROM (
                        completed_outages.resolved_at - completed_outages.created_at
                    ))::bigint AS duration_seconds,
                    servers.name AS server_name,
                    server_groups.id AS group_id,
                    server_groups.name AS group_name,
                    'agent'::varchar AS subject_name,
                    NULL::varchar AS unit
                FROM completed_outages
                INNER JOIN servers ON servers.id = completed_outages.server_id
                LEFT JOIN server_groups ON server_groups.id = servers.group_id
                WHERE completed_outages.resolved_at IS NOT NULL
            )
            SELECT *
            FROM events
            %s
            ORDER BY events.resolved_at DESC, events.created_at DESC, events.source_id DESC
            LIMIT 1000
            SQL
        );
        $statement = $this->pdo->prepare(sprintf($statement->queryString, $where));
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /** @return list<array{id: int|string, name: string}> */
    public function serverOptions(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, name FROM servers ORDER BY lower(name), id'
        )?->fetchAll() ?? [];

        return $rows;
    }

    /** @return list<array{id: int|string, name: string}> */
    public function groupOptions(): array
    {
        return $this->pdo->query(
            'SELECT id, name FROM server_groups ORDER BY sort_order, lower(name), id'
        )?->fetchAll() ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function attention(int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        $statement = $this->pdo->prepare(
            <<<'SQL'
            WITH issues AS (
                SELECT
                    alerts.server_id,
                    alerts.kind,
                    alerts.severity,
                    alerts.created_at,
                    COALESCE(metric_names.name, alerts.subject, alerts.kind) AS subject_name,
                    servers.name AS server_name,
                    server_groups.id AS group_id,
                    server_groups.name AS group_name
                FROM alerts
                INNER JOIN servers ON servers.id = alerts.server_id
                LEFT JOIN server_groups ON server_groups.id = servers.group_id
                LEFT JOIN metric_names ON metric_names.id = alerts.metric_id
                WHERE alerts.resolved = FALSE

                UNION ALL

                SELECT
                    servers.id AS server_id,
                    'offline'::varchar AS kind,
                    'critical'::varchar AS severity,
                    availability.changed_at AS created_at,
                    'agent'::varchar AS subject_name,
                    servers.name AS server_name,
                    server_groups.id AS group_id,
                    server_groups.name AS group_name
                FROM server_availability_state AS availability
                INNER JOIN servers ON servers.id = availability.server_id
                LEFT JOIN server_groups ON server_groups.id = servers.group_id
                WHERE availability.state = 'offline'
                  AND servers.is_active = TRUE
                  AND NOT EXISTS (
                      SELECT 1
                      FROM alerts
                      WHERE alerts.server_id = servers.id
                        AND alerts.kind = 'offline'
                        AND alerts.resolved = FALSE
                  )
            )
            SELECT *
            FROM issues
            ORDER BY
                CASE severity WHEN 'critical' THEN 0 ELSE 1 END,
                CASE kind WHEN 'offline' THEN 0 WHEN 'service' THEN 1 ELSE 2 END,
                created_at ASC,
                server_id ASC
            LIMIT :limit
            SQL
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param array{server_id?: int, group_id?: int, kind?: string, severity?: string, from?: string, to?: string} $filters
     * @return array{0: list<string>, 1: array<string, int|string>}
     */
    private function alertFilters(array $filters, string $dateColumn): array
    {
        $conditions = [];
        $parameters = [];
        if (isset($filters['server_id'])) {
            $conditions[] = 'alerts.server_id = :server_id';
            $parameters['server_id'] = $filters['server_id'];
        }
        if (isset($filters['group_id'])) {
            $conditions[] = 'server_groups.id = :group_id';
            $parameters['group_id'] = $filters['group_id'];
        }
        if (isset($filters['kind'])) {
            $conditions[] = 'alerts.kind = :kind';
            $parameters['kind'] = $filters['kind'];
        }
        if (isset($filters['severity'])) {
            $conditions[] = 'alerts.severity = :severity';
            $parameters['severity'] = $filters['severity'];
        }
        if (isset($filters['from'])) {
            $conditions[] = $dateColumn . ' >= :from';
            $parameters['from'] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $conditions[] = $dateColumn . ' < :to';
            $parameters['to'] = $filters['to'];
        }

        return [$conditions, $parameters];
    }
}
