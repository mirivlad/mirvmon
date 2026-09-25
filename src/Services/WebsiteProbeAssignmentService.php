<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class WebsiteProbeAssignmentService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,name:string}> */
    public function enabledAgents(): array
    {
        $statement = $this->pdo->query(
            "SELECT servers.id, servers.name
             FROM servers
             JOIN agent_configs ON agent_configs.server_id = servers.id
             WHERE servers.is_active = TRUE
               AND agent_configs.enabled = TRUE
               AND agent_configs.website_probe_enabled = TRUE
               AND jsonb_exists(servers.agent_capabilities, 'website_probe_v1')
             ORDER BY lower(servers.name), servers.id"
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ],
            $statement?->fetchAll() ?? []
        );
    }

    /**
     * @return array{
     *   revision:string,
     *   jobs:list<array{
     *     id:string,website_id:int,endpoint_id:int,url:string,method:string,
     *     interval_seconds:int,timeout_seconds:int,follow_redirects:bool,max_redirects:int
     *   }>
     * }
     */
    public function agentConfiguration(int $serverId): array
    {
        $enabled = $this->pdo->prepare(
            'SELECT website_probe_enabled AND enabled
             FROM agent_configs
             WHERE server_id = :server_id'
        );
        $enabled->execute(['server_id' => $serverId]);
        if (!$this->boolValue($enabled->fetchColumn())) {
            return ['revision' => hash('sha256', '[]'), 'jobs' => []];
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                websites.id AS website_id,
                endpoints.id AS endpoint_id,
                endpoints.url,
                endpoints.method,
                COALESCE(endpoints.interval_seconds, websites.default_interval_seconds) AS interval_seconds,
                endpoints.timeout_seconds,
                endpoints.follow_redirects,
                endpoints.max_redirects
            FROM website_probe_agents AS assignments
            JOIN websites ON websites.id = assignments.website_id
            JOIN website_endpoints AS endpoints ON endpoints.website_id = websites.id
            WHERE assignments.server_id = :server_id
              AND websites.is_active = TRUE
              AND endpoints.auth_type = 'none'
              AND endpoints.auth_encrypted IS NULL
              AND endpoints.headers_encrypted IS NULL
              AND endpoints.allow_self_signed = FALSE
            ORDER BY websites.id, endpoints.id
            SQL
        );
        $statement->execute(['server_id' => $serverId]);

        $jobs = [];
        foreach ($statement->fetchAll() as $row) {
            $websiteId = (int) $row['website_id'];
            $endpointId = (int) $row['endpoint_id'];
            $jobs[] = [
                'id' => 'website-' . $websiteId . '-endpoint-' . $endpointId,
                'website_id' => $websiteId,
                'endpoint_id' => $endpointId,
                'url' => (string) $row['url'],
                'method' => (string) $row['method'],
                'interval_seconds' => (int) $row['interval_seconds'],
                'timeout_seconds' => (int) $row['timeout_seconds'],
                'follow_redirects' => $this->boolValue($row['follow_redirects']),
                'max_redirects' => (int) $row['max_redirects'],
            ];
        }

        $encoded = json_encode($jobs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return ['revision' => hash('sha256', $encoded), 'jobs' => $jobs];
    }

    private function boolValue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
