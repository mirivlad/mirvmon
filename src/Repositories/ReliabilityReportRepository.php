<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\ReliabilityCalculator;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

final class ReliabilityReportRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ReliabilityCalculator $calculator = new ReliabilityCalculator(),
    ) {
    }

    /** @return array{servers:list<array<string,mixed>>,websites:list<array<string,mixed>>} */
    public function report(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $serverRows = $this->pdo->query(
            'SELECT servers.id, servers.name, servers.created_at,
                    COALESCE(agent_configs.interval_seconds, 60) AS interval_seconds
             FROM servers LEFT JOIN agent_configs ON agent_configs.server_id = servers.id
             WHERE servers.is_active = TRUE ORDER BY servers.name, servers.id'
        )->fetchAll();
        $websiteRows = $this->pdo->query(
            'SELECT websites.id, websites.name, websites.created_at,
                    endpoints.id AS endpoint_id, endpoints.created_at AS endpoint_created_at,
                    COALESCE(endpoints.interval_seconds, websites.default_interval_seconds) AS interval_seconds
             FROM websites
             INNER JOIN website_endpoints AS endpoints
               ON endpoints.website_id = websites.id AND endpoints.is_primary = TRUE
             WHERE websites.is_active = TRUE ORDER BY websites.name, websites.id'
        )->fetchAll();

        $serverSamples = $this->counts(
            'SELECT server_id AS id, count(*) AS sample_count
             FROM ingested_samples WHERE sample_time >= :from AND sample_time < :to
             GROUP BY server_id', $from, $to
        );
        $websiteSamples = $this->counts(
            'SELECT endpoint_id AS id, count(*) AS sample_count,
                    sum((transport_available AND assertions_passed)::integer) AS successful_count
             FROM website_check_samples
             WHERE sample_time >= :from AND sample_time < :to AND manual = FALSE
             GROUP BY endpoint_id', $from, $to
        );
        $incidents = $this->incidents($from, $to);
        $availabilityEvents = $this->serverEvents($from, $to);

        $servers = [];
        foreach ($serverRows as $row) {
            $id = (int) $row['id'];
            $start = $this->later($from, new DateTimeImmutable((string) $row['created_at']));
            if ($start >= $to) {
                continue;
            }
            $availability = $this->calculator->serverAvailability($availabilityEvents[$id] ?? [], $start, $to);
            $coverage = $this->calculator->coverage(
                (int) ($serverSamples[$id]['sample_count'] ?? 0), $start, $to, (int) $row['interval_seconds']
            );
            $windowSeconds = $to->getTimestamp() - $start->getTimestamp();
            $servers[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'availability' => $availability['availability_percent'],
                'known_seconds' => $availability['known_seconds'],
                'downtime_seconds' => $availability['offline_seconds'],
                'coverage' => $coverage,
                'reportable' => $coverage['coverage_percent'] >= 95.0
                    && $availability['known_seconds'] >= $windowSeconds * 0.95,
                'incidents' => $incidents['server'][$id] ?? ['count' => 0, 'duration_seconds' => 0, 'recovered_count' => 0, 'mean_recovery_seconds' => null],
            ];
        }

        $websites = [];
        foreach ($websiteRows as $row) {
            $id = (int) $row['id'];
            $endpointId = (int) $row['endpoint_id'];
            $start = $this->later(
                $this->later($from, new DateTimeImmutable((string) $row['created_at'])),
                new DateTimeImmutable((string) $row['endpoint_created_at'])
            );
            if ($start >= $to) {
                continue;
            }
            $samples = $websiteSamples[$endpointId] ?? [];
            $count = (int) ($samples['sample_count'] ?? 0);
            $coverage = $this->calculator->coverage($count, $start, $to, (int) $row['interval_seconds']);
            $websites[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'availability' => $count > 0
                    ? round(100 * (int) ($samples['successful_count'] ?? 0) / $count, 2)
                    : null,
                'coverage' => $coverage,
                'reportable' => $coverage['coverage_percent'] >= 95.0,
                'incidents' => $incidents['website'][$id] ?? ['count' => 0, 'duration_seconds' => 0, 'recovered_count' => 0, 'mean_recovery_seconds' => null],
            ];
        }

        $byProblem = static fn (array $left, array $right): int =>
            ($right['incidents']['count'] <=> $left['incidents']['count'])
            ?: ($right['incidents']['duration_seconds'] <=> $left['incidents']['duration_seconds'])
            ?: strcmp($left['name'], $right['name']);
        usort($servers, $byProblem);
        usort($websites, $byProblem);

        return ['servers' => $servers, 'websites' => $websites];
    }

    /** @return array<int,array<string,mixed>> */
    private function counts(string $sql, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['from' => $this->time($from), 'to' => $this->time($to)]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(int) $row['id']] = $row;
        }
        return $result;
    }

    /** @return array{server:array<int,array{count:int,duration_seconds:int,recovered_count:int,mean_recovery_seconds:?int}>,website:array<int,array{count:int,duration_seconds:int,recovered_count:int,mean_recovery_seconds:?int}>} */
    private function incidents(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $statement = $this->pdo->prepare(
            'SELECT server_id, website_id, count(*) AS incident_count,
                    sum(EXTRACT(EPOCH FROM (LEAST(COALESCE(resolved_at, :duration_to), :duration_end)
                        - GREATEST(created_at, :duration_from))))::bigint AS duration_seconds,
                    count(*) FILTER (WHERE resolved_at IS NOT NULL AND resolved_at <= :recovered_to) AS recovered_count,
                    (avg(EXTRACT(EPOCH FROM (resolved_at - created_at)))
                        FILTER (WHERE resolved_at IS NOT NULL AND resolved_at <= :recovery_to))::bigint AS mean_recovery_seconds
             FROM alerts
             WHERE created_at < :where_to AND (resolved_at IS NULL OR resolved_at > :where_from)
             GROUP BY server_id, website_id'
        );
        $statement->execute([
            'duration_to' => $this->time($to), 'duration_end' => $this->time($to),
            'duration_from' => $this->time($from), 'recovered_to' => $this->time($to),
            'recovery_to' => $this->time($to), 'where_to' => $this->time($to),
            'where_from' => $this->time($from),
        ]);
        $result = ['server' => [], 'website' => []];
        foreach ($statement->fetchAll() as $row) {
            $type = $row['server_id'] === null ? 'website' : 'server';
            $id = (int) ($row['server_id'] ?? $row['website_id']);
            $result[$type][$id] = [
                'count' => (int) $row['incident_count'],
                'duration_seconds' => max(0, (int) $row['duration_seconds']),
                'recovered_count' => (int) $row['recovered_count'],
                'mean_recovery_seconds' => $row['mean_recovery_seconds'] === null
                    ? null : (int) $row['mean_recovery_seconds'],
            ];
        }
        return $result;
    }

    /** @return array<int,list<array{state:string,occurred_at:string}>> */
    private function serverEvents(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $statement = $this->pdo->prepare(
            'SELECT server_id, state, occurred_at FROM (
                SELECT DISTINCT ON (server_id) server_id, state, occurred_at, id
                FROM server_availability_events WHERE occurred_at <= :previous_from
                ORDER BY server_id, occurred_at DESC, id DESC
             ) AS previous
             UNION ALL
             SELECT server_id, state, occurred_at FROM server_availability_events
             WHERE occurred_at > :range_from AND occurred_at < :range_to
             ORDER BY server_id, occurred_at'
        );
        $statement->execute([
            'previous_from' => $this->time($from),
            'range_from' => $this->time($from), 'range_to' => $this->time($to),
        ]);
        $events = [];
        foreach ($statement->fetchAll() as $row) {
            $events[(int) $row['server_id']][] = [
                'state' => (string) $row['state'],
                'occurred_at' => (string) $row['occurred_at'],
            ];
        }
        return $events;
    }

    private function later(DateTimeImmutable $left, DateTimeImmutable $right): DateTimeImmutable
    {
        return $left > $right ? $left : $right;
    }

    private function time(DateTimeImmutable $time): string
    {
        return $time->format(DateTimeInterface::ATOM);
    }
}
