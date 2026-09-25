<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Metrics\MetricsEnvelope;
use App\Domain\Websites\WebsiteCheckError;
use App\Domain\Websites\WebsiteCheckResult;
use DateTimeImmutable;
use PDO;

final class WebsiteProbeResultService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WebsiteIncidentService $incidents,
    ) {
    }

    public function ingest(int $serverId, MetricsEnvelope $envelope): void
    {
        if ($envelope->probeResults === []) {
            return;
        }

        foreach ($envelope->probeResults as $probe) {
            $endpoint = $this->assignedEndpoint(
                $serverId,
                (int) $probe['website_id'],
                (int) $probe['endpoint_id'],
            );
            // A queued result may arrive after an assignment was removed.
            if ($endpoint === null) {
                continue;
            }

            $errorKind = (string) ($probe['error_kind'] ?? '');
            $error = $errorKind === '' ? null : WebsiteCheckError::tryFrom($errorKind);
            if ($errorKind !== '' && $error === null) {
                $error = WebsiteCheckError::Internal;
            }

            $result = new WebsiteCheckResult(
                websiteId: (int) $probe['website_id'],
                endpointId: (int) $probe['endpoint_id'],
                sampleId: $envelope->sampleId,
                checkedAt: new DateTimeImmutable((string) $probe['observed_at']),
                transportAvailable: (bool) $probe['available'],
                assertionsPassed: true,
                statusCode: $probe['status_code'],
                configuredUrl: (string) $endpoint['url'],
                finalUrl: null,
                redirectChain: [],
                timings: [
                    'dns_ms' => null,
                    'tcp_ms' => null,
                    'tls_ms' => null,
                    'ttfb_ms' => null,
                    'total_ms' => (float) $probe['total_ms'],
                ],
                error: $error,
                assertionResults: [],
                manual: false,
                probeKind: 'agent',
                probeId: (string) $serverId,
                safeMessage: (string) ($probe['safe_message'] ?? ''),
            );
            $this->incidents->recordRemoteHttp($result);
        }
    }

    /** @return array{url:string}|null */
    private function assignedEndpoint(int $serverId, int $websiteId, int $endpointId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT endpoints.url
            FROM website_probe_agents AS assignments
            JOIN agent_configs ON agent_configs.server_id = assignments.server_id
            JOIN websites ON websites.id = assignments.website_id
            JOIN website_endpoints AS endpoints
              ON endpoints.website_id = websites.id
             AND endpoints.id = :endpoint_id
            WHERE assignments.server_id = :server_id
              AND assignments.website_id = :website_id
              AND agent_configs.website_probe_enabled = TRUE
              AND agent_configs.enabled = TRUE
              AND websites.is_active = TRUE
              AND endpoints.auth_type = 'none'
              AND endpoints.auth_encrypted IS NULL
              AND endpoints.headers_encrypted IS NULL
              AND endpoints.allow_self_signed = FALSE
            SQL
        );
        $statement->execute([
            'server_id' => $serverId,
            'website_id' => $websiteId,
            'endpoint_id' => $endpointId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? ['url' => (string) $row['url']] : null;
    }
}
