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
     * Active alert incidents plus current offline availability states that do not
     * already have an offline alert. Availability is operational truth; alert
     * creation remains controlled by notification preferences.
     *
     * @param array{source_type?: string, server_id?: int, website_id?: int, endpoint_id?: int, group_id?: int, kind?: string, severity?: string, from?: string, to?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function active(array $filters = []): array
    {
        [$conditions, $parameters] = $this->eventFilters($filters, 'issues');
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $sql = sprintf(
            <<<'SQL'
            WITH issues AS (
                SELECT
                    'alert'::varchar AS source,
                    'server'::varchar AS source_type,
                    alerts.id,
                    alerts.server_id,
                    NULL::bigint AS website_id,
                    NULL::bigint AS endpoint_id,
                    alerts.kind,
                    alerts.subject,
                    alerts.value,
                    alerts.severity,
                    alerts.created_at,
                    NULL::timestamptz AS resolved_at,
                    NULL::varchar AS resolved_by_username,
                    EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - alerts.created_at))::bigint AS duration_seconds,
                    servers.name AS server_name,
                    NULL::varchar AS source_name,
                    NULL::varchar AS endpoint_name,
                    monitoring_groups.id AS group_id,
                    monitoring_groups.name AS group_name,
                    COALESCE(metric_names.name, alerts.subject, alerts.kind) AS subject_name,
                    metric_names.unit
                FROM alerts
                INNER JOIN servers ON servers.id = alerts.server_id
                LEFT JOIN monitoring_groups ON monitoring_groups.id = servers.group_id
                LEFT JOIN metric_names ON metric_names.id = alerts.metric_id
                WHERE alerts.resolved = FALSE

                UNION ALL

                SELECT
                    'alert'::varchar AS source,
                    'website'::varchar AS source_type,
                    alerts.id,
                    NULL::bigint AS server_id,
                    alerts.website_id,
                    alerts.endpoint_id,
                    alerts.kind,
                    alerts.subject,
                    alerts.value,
                    alerts.severity,
                    alerts.created_at,
                    NULL::timestamptz AS resolved_at,
                    NULL::varchar AS resolved_by_username,
                    EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - alerts.created_at))::bigint AS duration_seconds,
                    NULL::varchar AS server_name,
                    websites.name AS source_name,
                    endpoints.name AS endpoint_name,
                    monitoring_groups.id AS group_id,
                    monitoring_groups.name AS group_name,
                    COALESCE(endpoints.name, alerts.subject, alerts.kind) AS subject_name,
                    NULL::varchar AS unit
                FROM alerts
                INNER JOIN websites ON websites.id = alerts.website_id
                LEFT JOIN website_endpoints AS endpoints ON endpoints.id = alerts.endpoint_id
                LEFT JOIN monitoring_groups ON monitoring_groups.id = websites.group_id
                WHERE alerts.resolved = FALSE

                UNION ALL

                SELECT
                    'availability'::varchar AS source,
                    'server'::varchar AS source_type,
                    NULL::bigint AS id,
                    servers.id AS server_id,
                    NULL::bigint AS website_id,
                    NULL::bigint AS endpoint_id,
                    'offline'::varchar AS kind,
                    'agent'::varchar AS subject,
                    NULL::double precision AS value,
                    'critical'::varchar AS severity,
                    availability.changed_at AS created_at,
                    NULL::timestamptz AS resolved_at,
                    NULL::varchar AS resolved_by_username,
                    EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - availability.changed_at))::bigint AS duration_seconds,
                    servers.name AS server_name,
                    NULL::varchar AS source_name,
                    NULL::varchar AS endpoint_name,
                    monitoring_groups.id AS group_id,
                    monitoring_groups.name AS group_name,
                    'agent'::varchar AS subject_name,
                    NULL::varchar AS unit
                FROM server_availability_state AS availability
                INNER JOIN servers ON servers.id = availability.server_id
                LEFT JOIN monitoring_groups ON monitoring_groups.id = servers.group_id
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
            %s
            ORDER BY
                CASE issues.severity WHEN 'critical' THEN 0 ELSE 1 END,
                CASE issues.kind WHEN 'offline' THEN 0 WHEN 'service' THEN 1 ELSE 2 END,
                issues.created_at ASC,
                issues.server_id ASC,
                issues.id ASC NULLS LAST
            SQL,
            $where
        );
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * Completed metric/service incidents plus completed offline periods from the
     * availability transition log. Offline alert rows are intentionally omitted
     * here so a single outage is not shown twice.
     *
     * @param array{source_type?: string, server_id?: int, website_id?: int, endpoint_id?: int, group_id?: int, kind?: string, severity?: string, from?: string, to?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function history(array $filters = []): array
    {
        [$conditions, $parameters] = $this->eventFilters($filters, 'events');
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $sql = sprintf(
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
                    'server'::varchar AS source_type,
                    alerts.id AS source_id,
                    alerts.server_id,
                    NULL::bigint AS website_id,
                    NULL::bigint AS endpoint_id,
                    alerts.kind,
                    alerts.severity,
                    alerts.value,
                    alerts.created_at,
                    alerts.resolved_at,
                    alerts.resolved_by_username,
                    EXTRACT(EPOCH FROM (alerts.resolved_at - alerts.created_at))::bigint AS duration_seconds,
                    servers.name AS server_name,
                    NULL::varchar AS source_name,
                    NULL::varchar AS endpoint_name,
                    monitoring_groups.id AS group_id,
                    monitoring_groups.name AS group_name,
                    COALESCE(metric_names.name, alerts.subject, alerts.kind) AS subject_name,
                    metric_names.unit
                FROM alerts
                INNER JOIN servers ON servers.id = alerts.server_id
                LEFT JOIN monitoring_groups ON monitoring_groups.id = servers.group_id
                LEFT JOIN metric_names ON metric_names.id = alerts.metric_id
                WHERE alerts.resolved = TRUE
                  AND alerts.kind <> 'offline'

                UNION ALL

                SELECT
                    'alert'::varchar AS source,
                    'website'::varchar AS source_type,
                    alerts.id AS source_id,
                    NULL::bigint AS server_id,
                    alerts.website_id,
                    alerts.endpoint_id,
                    alerts.kind,
                    alerts.severity,
                    alerts.value,
                    alerts.created_at,
                    alerts.resolved_at,
                    alerts.resolved_by_username,
                    EXTRACT(EPOCH FROM (alerts.resolved_at - alerts.created_at))::bigint AS duration_seconds,
                    NULL::varchar AS server_name,
                    websites.name AS source_name,
                    endpoints.name AS endpoint_name,
                    monitoring_groups.id AS group_id,
                    monitoring_groups.name AS group_name,
                    COALESCE(endpoints.name, alerts.subject, alerts.kind) AS subject_name,
                    NULL::varchar AS unit
                FROM alerts
                INNER JOIN websites ON websites.id = alerts.website_id
                LEFT JOIN website_endpoints AS endpoints ON endpoints.id = alerts.endpoint_id
                LEFT JOIN monitoring_groups ON monitoring_groups.id = websites.group_id
                WHERE alerts.resolved = TRUE

                UNION ALL

                SELECT
                    'availability'::varchar AS source,
                    'server'::varchar AS source_type,
                    completed_outages.id AS source_id,
                    completed_outages.server_id,
                    NULL::bigint AS website_id,
                    NULL::bigint AS endpoint_id,
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
                    NULL::varchar AS source_name,
                    NULL::varchar AS endpoint_name,
                    monitoring_groups.id AS group_id,
                    monitoring_groups.name AS group_name,
                    'agent'::varchar AS subject_name,
                    NULL::varchar AS unit
                FROM completed_outages
                INNER JOIN servers ON servers.id = completed_outages.server_id
                LEFT JOIN monitoring_groups ON monitoring_groups.id = servers.group_id
                WHERE completed_outages.resolved_at IS NOT NULL
            )
            SELECT *
            FROM events
            %s
            ORDER BY events.resolved_at DESC, events.created_at DESC, events.source_id DESC
            LIMIT 1000
            SQL,
            $where
        );
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /** @return list<array{id: int|string, name: string}> */
    public function serverOptions(): array
    {
        return $this->pdo->query(
            'SELECT id, name FROM servers ORDER BY lower(name), id'
        )?->fetchAll() ?? [];
    }

    /** @return list<array{id: int|string, name: string}> */
    public function groupOptions(): array
    {
        return $this->pdo->query(
            'SELECT id, name FROM monitoring_groups ORDER BY sort_order, lower(name), id'
        )?->fetchAll() ?? [];
    }

    /** @return list<array{id: int|string, name: string}> */
    public function websiteOptions(): array
    {
        return $this->pdo->query(
            'SELECT id, name FROM websites ORDER BY lower(name), id'
        )?->fetchAll() ?? [];
    }

    /** @return list<array{id: int|string, website_id: int|string, name: string}> */
    public function endpointOptions(): array
    {
        return $this->pdo->query(
            'SELECT endpoints.id, endpoints.website_id, endpoints.name
             FROM website_endpoints AS endpoints
             INNER JOIN websites ON websites.id = endpoints.website_id
             ORDER BY lower(websites.name), lower(endpoints.name), endpoints.id'
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
                    'server'::varchar AS source_type,
                    alerts.server_id,
                    NULL::bigint AS website_id,
                    NULL::bigint AS endpoint_id,
                    alerts.kind,
                    alerts.severity,
                    alerts.created_at,
                    COALESCE(metric_names.name, alerts.subject, alerts.kind) AS subject_name,
                    servers.name AS server_name,
                    NULL::varchar AS source_name,
                    NULL::varchar AS endpoint_name,
                    monitoring_groups.id AS group_id,
                    monitoring_groups.name AS group_name
                FROM alerts
                INNER JOIN servers ON servers.id = alerts.server_id
                LEFT JOIN monitoring_groups ON monitoring_groups.id = servers.group_id
                LEFT JOIN metric_names ON metric_names.id = alerts.metric_id
                WHERE alerts.resolved = FALSE

                UNION ALL

                SELECT
                    'website'::varchar AS source_type,
                    NULL::bigint AS server_id,
                    alerts.website_id,
                    alerts.endpoint_id,
                    alerts.kind,
                    alerts.severity,
                    alerts.created_at,
                    COALESCE(endpoints.name, alerts.subject, alerts.kind) AS subject_name,
                    NULL::varchar AS server_name,
                    websites.name AS source_name,
                    endpoints.name AS endpoint_name,
                    monitoring_groups.id AS group_id,
                    monitoring_groups.name AS group_name
                FROM alerts
                INNER JOIN websites ON websites.id = alerts.website_id
                LEFT JOIN website_endpoints AS endpoints ON endpoints.id = alerts.endpoint_id
                LEFT JOIN monitoring_groups ON monitoring_groups.id = websites.group_id
                WHERE alerts.resolved = FALSE

                UNION ALL

                SELECT
                    'server'::varchar AS source_type,
                    servers.id AS server_id,
                    NULL::bigint AS website_id,
                    NULL::bigint AS endpoint_id,
                    'offline'::varchar AS kind,
                    'critical'::varchar AS severity,
                    availability.changed_at AS created_at,
                    'agent'::varchar AS subject_name,
                    servers.name AS server_name,
                    NULL::varchar AS source_name,
                    NULL::varchar AS endpoint_name,
                    monitoring_groups.id AS group_id,
                    monitoring_groups.name AS group_name
                FROM server_availability_state AS availability
                INNER JOIN servers ON servers.id = availability.server_id
                LEFT JOIN monitoring_groups ON monitoring_groups.id = servers.group_id
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
     * @param array{source_type?: string, server_id?: int, website_id?: int, endpoint_id?: int, group_id?: int, kind?: string, severity?: string, from?: string, to?: string} $filters
     * @return array{0: list<string>, 1: array<string, int|string>}
     */
    private function eventFilters(array $filters, string $alias): array
    {
        $conditions = [];
        $parameters = [];
        if (isset($filters['source_type'])) {
            $conditions[] = $alias . '.source_type = :source_type';
            $parameters['source_type'] = $filters['source_type'];
        }
        if (isset($filters['server_id'])) {
            $conditions[] = $alias . '.server_id = :server_id';
            $parameters['server_id'] = $filters['server_id'];
        }
        if (isset($filters['website_id'])) {
            $conditions[] = $alias . '.website_id = :website_id';
            $parameters['website_id'] = $filters['website_id'];
        }
        if (isset($filters['endpoint_id'])) {
            $conditions[] = $alias . '.endpoint_id = :endpoint_id';
            $parameters['endpoint_id'] = $filters['endpoint_id'];
        }
        if (isset($filters['group_id'])) {
            $conditions[] = $alias . '.group_id = :group_id';
            $parameters['group_id'] = $filters['group_id'];
        }
        if (isset($filters['kind'])) {
            $conditions[] = $alias . '.kind = :kind';
            $parameters['kind'] = $filters['kind'];
        }
        if (isset($filters['severity'])) {
            $conditions[] = $alias . '.severity = :severity';
            $parameters['severity'] = $filters['severity'];
        }
        if (isset($filters['from'])) {
            $conditions[] = $alias . '.created_at >= :from';
            $parameters['from'] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $conditions[] = $alias . '.created_at < :to';
            $parameters['to'] = $filters['to'];
        }

        return [$conditions, $parameters];
    }
}
