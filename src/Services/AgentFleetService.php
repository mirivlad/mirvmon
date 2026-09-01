<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class AgentFleetService
{
    /** @var list<string> */
    private const ACTIVE_UPDATE_STATES = [
        'pending', 'accepted', 'downloading', 'installing', 'awaiting_restart',
    ];

    /** @var list<string> */
    private const VIEWS = ['all', 'current', 'outdated', 'updating', 'attention', 'offline', 'unknown'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AgentUpdateService $updates,
        private readonly ServerStatusService $status
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(string $view = 'all', string $search = ''): array
    {
        $view = in_array($view, self::VIEWS, true) ? $view : 'all';
        $search = trim($search);
        $servers = $this->status->enrich($this->servers());
        $statuses = $this->updates->statusesForServers(array_map(
            static fn (array $server): int => (int) $server['id'],
            $servers
        ));

        $summary = [
            'total' => count($servers),
            'reported' => 0,
            'current' => 0,
            'outdated' => 0,
            'updating' => 0,
            'attention' => 0,
            'offline' => 0,
            'unknown' => 0,
        ];
        $versions = [];
        $rows = [];

        foreach ($servers as $server) {
            $serverId = (int) $server['id'];
            $update = $statuses[$serverId] ?? null;
            $server['agent_update'] = $update;
            $server['connection_state'] = $this->connectionState($server);
            $server['fleet_state'] = $this->fleetState($update);

            $installed = is_string($update['installed_version'] ?? null)
                ? $update['installed_version']
                : null;
            if ($installed === null) {
                $summary['unknown']++;
            } else {
                $summary['reported']++;
                $versions[$installed] = ($versions[$installed] ?? 0) + 1;
                if (($update['is_outdated'] ?? null) === true) {
                    $summary['outdated']++;
                } else {
                    $summary['current']++;
                }
            }
            if ($server['fleet_state'] === 'updating') {
                $summary['updating']++;
            }
            if ($server['fleet_state'] === 'attention') {
                $summary['attention']++;
            }
            if (in_array($server['connection_state'], ['disabled', 'never', 'offline'], true)) {
                $summary['offline']++;
            }

            if ($this->matchesView($server, $view) && $this->matchesSearch($server, $search)) {
                $rows[] = $server;
            }
        }

        uksort($versions, static fn (string $left, string $right): int => version_compare($right, $left));
        usort($rows, fn (array $left, array $right): int => $this->rowPriority($left) <=> $this->rowPriority($right)
            ?: strnatcasecmp((string) $left['name'], (string) $right['name']));

        return [
            'view' => $view,
            'search' => $search,
            'summary' => $summary,
            'versions' => $versions,
            'target_version' => (string) $this->updates->summarizeStatuses($statuses)['target_version'],
            'servers' => $rows,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function servers(): array
    {
        $statement = $this->pdo->query(
            "SELECT servers.*,
                    agent_tokens.last_used_at AS last_contact_at,
                    monitoring_groups.name AS group_name,
                    COALESCE(alert_counts.warning_alerts, 0) AS warning_alerts,
                    COALESCE(alert_counts.critical_alerts, 0) AS critical_alerts
             FROM servers
             LEFT JOIN agent_tokens ON agent_tokens.server_id = servers.id
             LEFT JOIN monitoring_groups ON monitoring_groups.id = servers.group_id
             LEFT JOIN LATERAL (
                SELECT count(*) FILTER (WHERE severity = 'warning') AS warning_alerts,
                       count(*) FILTER (WHERE severity = 'critical') AS critical_alerts
                FROM alerts
                WHERE alerts.server_id = servers.id AND alerts.resolved = FALSE
             ) AS alert_counts ON TRUE
             ORDER BY servers.name ASC, servers.id ASC"
        );

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $server */
    private function connectionState(array $server): string
    {
        if (!$this->bool($server['is_active'] ?? true)) {
            return 'disabled';
        }
        if ($server['last_contact_at'] === null) {
            return 'never';
        }
        return ($server['status'] ?? 'offline') === 'offline' ? 'offline' : 'connected';
    }

    /** @param array<string, mixed>|null $update */
    private function fleetState(?array $update): string
    {
        $state = is_string($update['state'] ?? null) ? $update['state'] : 'unknown';
        if (in_array($state, ['failed', 'manual_required'], true)) {
            return 'attention';
        }
        if (in_array($state, self::ACTIVE_UPDATE_STATES, true)) {
            return 'updating';
        }
        if (($update['is_outdated'] ?? null) === true) {
            return 'outdated';
        }
        return is_string($update['installed_version'] ?? null) ? 'current' : 'unknown';
    }

    /** @param array<string, mixed> $server */
    private function matchesView(array $server, string $view): bool
    {
        return match ($view) {
            'current' => ($server['agent_update']['installed_version'] ?? null) !== null
                && ($server['agent_update']['is_outdated'] ?? null) === false,
            'outdated' => ($server['agent_update']['is_outdated'] ?? null) === true,
            'updating' => $server['fleet_state'] === 'updating',
            'attention' => $server['fleet_state'] === 'attention',
            'offline' => in_array($server['connection_state'], ['disabled', 'never', 'offline'], true),
            'unknown' => $server['fleet_state'] === 'unknown',
            default => true,
        };
    }

    /** @param array<string, mixed> $server */
    private function matchesSearch(array $server, string $search): bool
    {
        if ($search === '') {
            return true;
        }
        $haystack = implode(' ', [
            (string) ($server['name'] ?? ''),
            (string) ($server['address'] ?? ''),
            (string) ($server['group_name'] ?? ''),
            (string) ($server['os_version'] ?? ''),
            (string) ($server['agent_version'] ?? ''),
            (string) ($server['agent_artifact'] ?? ''),
        ]);
        return stripos($haystack, $search) !== false;
    }

    /** @param array<string, mixed> $server */
    private function rowPriority(array $server): int
    {
        return match ($server['fleet_state']) {
            'attention' => 0,
            'updating' => 1,
            'outdated' => 2,
            'unknown' => 3,
            default => in_array($server['connection_state'], ['disabled', 'never', 'offline'], true) ? 4 : 5,
        };
    }


    private function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || in_array($value, ['1', 't', 'true'], true);
    }
}
