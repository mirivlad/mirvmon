<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Websites\DomainRegistrationResult;
use App\Domain\Websites\TlsInspectionResult;
use App\Domain\Websites\WebsiteCheckResult;
use App\Domain\Websites\WebsiteEndpointDefinition;
use App\Repositories\WebsiteRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class CentralWebsiteProbeExecutor implements WebsiteProbeExecutor
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WebsiteRepository $websites,
        private readonly WebsiteHttpChecker $http,
        private readonly TlsCertificateInspector $tls,
        private readonly DomainRegistrationChecker $domains,
        private readonly WebsiteEndpointValidator $validator = new WebsiteEndpointValidator(),
    ) {
    }

    public function execute(array $jobs, int $concurrency): array
    {
        $httpJobs = [];
        $results = [];
        foreach ($jobs as $job) {
            $kind = (string) ($job['kind'] ?? '');
            if ($kind === 'http') {
                $row = $this->websites->endpointForCheck((int) $job['endpoint_id']);
                if ($row === null) {
                    throw new RuntimeException('Website endpoint is missing.');
                }
                $httpJobs[] = [
                    'definition' => $this->definition($row),
                    'website_id' => (int) $job['website_id'],
                    'endpoint_id' => (int) $job['endpoint_id'],
                    'manual' => (bool) $job['manual'],
                    'job_id' => (int) $job['id'],
                ];
            } elseif ($kind === 'tls') {
                $results[] = [
                    'job_id' => (int) $job['id'],
                    'result' => $this->tlsResult($job),
                ];
            } elseif ($kind === 'domain') {
                $results[] = [
                    'job_id' => (int) $job['id'],
                    'result' => $this->domains->check($this->domain((int) $job['website_id']), new DateTimeImmutable()),
                ];
            }
        }
        foreach ($this->http->checkMany($httpJobs, max(1, min(50, $concurrency))) as $index => $result) {
            $results[] = ['job_id' => (int) $httpJobs[$index]['job_id'], 'result' => $result];
        }

        return $results;
    }

    /** @param array<string, mixed> $row */
    private function definition(array $row): WebsiteEndpointDefinition
    {
        $ranges = $row['expected_status_ranges'] ?? [];
        if (is_string($ranges)) {
            $ranges = json_decode($ranges, true);
        }
        $statusText = is_array($ranges) ? implode(',', array_map(
            static fn (array $range): string => (int) $range['min'] === (int) $range['max']
                ? (string) $range['min'] : $range['min'] . '-' . $range['max'],
            $ranges,
        )) : '200-299';
        $hosts = $row['credential_redirect_hosts'] ?? [];
        if (is_string($hosts)) {
            $hosts = json_decode($hosts, true);
        }

        return $this->validator->validate([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'url' => (string) $row['url'],
            'method' => (string) $row['method'],
            'interval_seconds' => (int) ($row['interval_seconds'] ?? 60),
            'timeout_seconds' => (int) $row['timeout_seconds'],
            'follow_redirects' => $this->bool($row['follow_redirects']),
            'max_redirects' => (int) $row['max_redirects'],
            'status_check_enabled' => $this->bool($row['status_check_enabled']),
            'expected_statuses' => $statusText,
            'content_checks' => $this->contentChecks($row['content_checks'] ?? []),
            'warning_total_ms' => $row['warning_total_ms'],
            'critical_total_ms' => $row['critical_total_ms'],
            'auth_type' => (string) $row['auth_type'],
            'auth_username' => $row['auth_username'],
            'auth_secret' => $row['auth_secret'],
            'headers' => $this->headerRows($row['headers'] ?? []),
            'credential_redirect_hosts' => $hosts,
            'allow_self_signed' => $this->bool($row['allow_self_signed']),
            'tls_expiry_enabled' => $this->bool($row['tls_expiry_enabled']),
        ]);
    }

    /** @param array<string, mixed> $job */
    private function tlsResult(array $job): TlsInspectionResult
    {
        $statement = $this->pdo->prepare(
            'SELECT targets.*, endpoints.allow_self_signed
             FROM website_tls_targets AS targets JOIN website_endpoints AS endpoints ON endpoints.id = targets.endpoint_id
             WHERE targets.id = :id'
        );
        $statement->execute(['id' => $job['tls_target_id']]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('TLS target is missing.');
        }

        return $this->tls->inspect(
            (int) $row['id'],
            (int) $row['endpoint_id'],
            (string) $row['hostname'],
            (int) $row['port'],
            $this->bool($row['allow_self_signed']),
            new DateTimeImmutable(),
        );
    }

    private function domain(int $websiteId): string
    {
        $statement = $this->pdo->prepare('SELECT registration_domain FROM websites WHERE id = :id');
        $statement->execute(['id' => $websiteId]);

        return (string) $statement->fetchColumn();
    }

    /** @return list<array{name: string, value: string}> */
    private function headerRows(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }
        if ($headers === [] || array_is_list($headers) === false) {
            $result = [];
            foreach ($headers as $name => $value) {
                $result[] = ['name' => (string) $name, 'value' => (string) $value];
            }

            return $result;
        }
        $result = [];
        foreach ($headers as $header) {
            if (is_array($header) && isset($header['name'], $header['value'])) {
                $result[] = ['name' => (string) $header['name'], 'value' => (string) $header['value']];
            }
        }

        return $result;
    }

    /** @return list<array{kind: string, selector?: string, needle: string}> */
    private function contentChecks(mixed $checks): array
    {
        if (!is_array($checks)) {
            return [];
        }

        $result = [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $result[] = [
                'kind' => (string) ($check['kind'] ?? ''),
                'selector' => isset($check['selector']) ? (string) $check['selector'] : null,
                'needle' => (string) ($check['needle'] ?? $check['expected_text'] ?? ''),
            ];
        }

        return $result;
    }

    private function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
