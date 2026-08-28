<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Websites\WebsiteCheckResult;
use App\Services\WebsiteUrlSanitizer;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDO;

final class WebsiteMetricsRepository
{
    private const RAW_MAX_SECONDS = 86400;
    private const HOURLY_MAX_SECONDS = 2592000;
    private const MAX_POINTS = 500;
    private const FORBIDDEN_DIAGNOSTIC_KEY = '/(?:body|headers?|token|password|authorization)/i';

    private readonly WebsiteUrlSanitizer $urlSanitizer;

    public function __construct(private readonly PDO $pdo)
    {
        $this->urlSanitizer = new WebsiteUrlSanitizer();
    }

    public function record(WebsiteCheckResult $result): void
    {
        $configuredUrl = $this->urlSanitizer->forDisplay($result->configuredUrl);
        $finalUrl = $result->finalUrl === null
            ? null
            : $this->urlSanitizer->forDisplay($result->finalUrl);
        $redirectChain = $this->safeDiagnosticArray($result->redirectChain);
        $assertions = $this->safeDiagnosticArray($result->assertionResults);

        $previous = $this->pdo->prepare(
            'SELECT final_url
             FROM website_check_samples
             WHERE endpoint_id = :endpoint_id
             ORDER BY sample_time DESC, sample_id DESC
             LIMIT 1'
        );
        $previous->execute(['endpoint_id' => $result->endpointId]);
        $previousFinalUrl = $previous->fetchColumn();
        $finalUrlChanged = $previousFinalUrl === false || $previousFinalUrl !== $finalUrl;
        $storeRedirectChain = $result->manual
            || !$result->transportAvailable
            || !$result->assertionsPassed
            || $result->error !== null
            || $finalUrlChanged;

        $diagnostics = ['assertions' => $assertions];
        if ($result->error !== null) {
            $diagnostics['error_kind'] = $result->error->value;
        }
        if ($storeRedirectChain && $redirectChain !== []) {
            $diagnostics['redirect_chain'] = $redirectChain;
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO website_check_samples (
                sample_time,
                website_id,
                endpoint_id,
                sample_id,
                probe_kind,
                probe_id,
                manual,
                transport_available,
                assertions_passed,
                status_code,
                configured_url,
                final_url,
                redirect_count,
                dns_ms,
                tcp_ms,
                tls_ms,
                ttfb_ms,
                total_ms,
                error_kind,
                diagnostics
            ) VALUES (
                :sample_time,
                :website_id,
                :endpoint_id,
                :sample_id,
                :probe_kind,
                :probe_id,
                :manual,
                :transport_available,
                :assertions_passed,
                :status_code,
                :configured_url,
                :final_url,
                :redirect_count,
                :dns_ms,
                :tcp_ms,
                :tls_ms,
                :ttfb_ms,
                :total_ms,
                :error_kind,
                CAST(:diagnostics AS jsonb)
            )
            SQL
        );
        $statement->execute([
            'sample_time' => $result->checkedAt->format(DateTimeInterface::ATOM),
            'website_id' => $result->websiteId,
            'endpoint_id' => $result->endpointId,
            'sample_id' => $result->sampleId,
            'probe_kind' => $result->probeKind,
            'probe_id' => $result->probeId,
            'manual' => $result->manual ? 1 : 0,
            'transport_available' => $result->transportAvailable ? 1 : 0,
            'assertions_passed' => $result->assertionsPassed ? 1 : 0,
            'status_code' => $result->statusCode,
            'configured_url' => $configuredUrl,
            'final_url' => $finalUrl,
            'redirect_count' => count($result->redirectChain),
            'dns_ms' => $result->timings['dns_ms'],
            'tcp_ms' => $result->timings['tcp_ms'],
            'tls_ms' => $result->timings['tls_ms'],
            'ttfb_ms' => $result->timings['ttfb_ms'],
            'total_ms' => $result->timings['total_ms'],
            'error_kind' => $result->error?->value,
            'diagnostics' => json_encode(
                $diagnostics,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    /**
     * @return array{
     *     source: 'raw'|'hourly'|'daily',
     *     bucket_seconds: int,
     *     points: list<array{
     *         endpoint_id: int,
     *         time_bucket: string,
     *         transport_ratio: float,
     *         assertion_ratio: float,
     *         ttfb_min_ms: ?float,
     *         ttfb_avg_ms: ?float,
     *         ttfb_max_ms: ?float,
     *         ttfb_sample_count: int,
     *         total_min_ms: ?float,
     *         total_avg_ms: ?float,
     *         total_max_ms: ?float,
     *         total_sample_count: int,
     *         sample_count: int
     *     }>
     * }
     */
    public function series(
        int $websiteId,
        ?int $endpointId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $duration = $to->getTimestamp() - $from->getTimestamp();
        if ($duration <= 0) {
            throw new InvalidArgumentException('Website metric range end must be after start.');
        }

        $source = $this->sourceForRange($from, $to);
        $bucketSeconds = $this->bucketSeconds($duration, $source);
        $endpointFilter = $endpointId === null ? '' : 'AND endpoint_id = :endpoint_id';

        if ($source === 'raw') {
            $sql = <<<SQL
                SELECT
                    endpoint_id,
                    time_bucket(
                        CAST(:bucket_seconds AS integer) * INTERVAL '1 second',
                        sample_time
                    ) AS time_bucket,
                    avg(transport_available::integer) AS transport_ratio,
                    avg(assertions_passed::integer) AS assertion_ratio,
                    min(ttfb_ms) AS ttfb_min_ms,
                    avg(ttfb_ms) AS ttfb_avg_ms,
                    max(ttfb_ms) AS ttfb_max_ms,
                    count(ttfb_ms) AS ttfb_sample_count,
                    min(total_ms) AS total_min_ms,
                    avg(total_ms) AS total_avg_ms,
                    max(total_ms) AS total_max_ms,
                    count(total_ms) AS total_sample_count,
                    count(*) AS sample_count
                FROM website_check_samples
                WHERE website_id = :website_id
                  AND sample_time >= :range_start
                  AND sample_time <= :range_end
                  {$endpointFilter}
                GROUP BY endpoint_id, time_bucket
                ORDER BY time_bucket, endpoint_id
                SQL;
        } else {
            $table = $source === 'hourly'
                ? 'website_check_samples_hourly'
                : 'website_check_samples_daily';
            $sql = <<<SQL
                SELECT
                    endpoint_id,
                    time_bucket(
                        CAST(:bucket_seconds AS integer) * INTERVAL '1 second',
                        bucket
                    ) AS time_bucket,
                    sum(transport_available_count)::double precision
                        / NULLIF(sum(sample_count), 0) AS transport_ratio,
                    sum(assertions_passed_count)::double precision
                        / NULLIF(sum(sample_count), 0) AS assertion_ratio,
                    min(ttfb_min_ms) AS ttfb_min_ms,
                    sum(ttfb_avg_ms * ttfb_count)
                        / NULLIF(sum(ttfb_count), 0) AS ttfb_avg_ms,
                    max(ttfb_max_ms) AS ttfb_max_ms,
                    sum(ttfb_count) AS ttfb_sample_count,
                    min(total_min_ms) AS total_min_ms,
                    sum(total_avg_ms * total_count)
                        / NULLIF(sum(total_count), 0) AS total_avg_ms,
                    max(total_max_ms) AS total_max_ms,
                    sum(total_count) AS total_sample_count,
                    sum(sample_count) AS sample_count
                FROM {$table}
                WHERE website_id = :website_id
                  AND bucket >= :range_start
                  AND bucket <= :range_end
                  {$endpointFilter}
                GROUP BY endpoint_id, time_bucket
                ORDER BY time_bucket, endpoint_id
                SQL;
        }

        $params = [
            'website_id' => $websiteId,
            'range_start' => $from->format(DateTimeInterface::ATOM),
            'range_end' => $to->format(DateTimeInterface::ATOM),
            'bucket_seconds' => $bucketSeconds,
        ];
        if ($endpointId !== null) {
            $params['endpoint_id'] = $endpointId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        $points = [];
        foreach ($statement->fetchAll() as $row) {
            $points[] = [
                'endpoint_id' => (int) $row['endpoint_id'],
                'time_bucket' => (string) $row['time_bucket'],
                'transport_ratio' => (float) $row['transport_ratio'],
                'assertion_ratio' => (float) $row['assertion_ratio'],
                'ttfb_min_ms' => $this->nullableFloat($row['ttfb_min_ms']),
                'ttfb_avg_ms' => $this->nullableFloat($row['ttfb_avg_ms']),
                'ttfb_max_ms' => $this->nullableFloat($row['ttfb_max_ms']),
                'ttfb_sample_count' => (int) $row['ttfb_sample_count'],
                'total_min_ms' => $this->nullableFloat($row['total_min_ms']),
                'total_avg_ms' => $this->nullableFloat($row['total_avg_ms']),
                'total_max_ms' => $this->nullableFloat($row['total_max_ms']),
                'total_sample_count' => (int) $row['total_sample_count'],
                'sample_count' => (int) $row['sample_count'],
            ];
        }

        return [
            'source' => $source,
            'bucket_seconds' => $bucketSeconds,
            'points' => $points,
        ];
    }

    /** @return 'raw'|'hourly'|'daily' */
    public function sourceForRange(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Website metric range end must be after start.');
        }
        if ($seconds <= self::RAW_MAX_SECONDS) {
            return 'raw';
        }
        if ($seconds <= self::HOURLY_MAX_SECONDS) {
            return 'hourly';
        }

        return 'daily';
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $websiteId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT DISTINCT ON (endpoint_id)
                endpoint_id,
                sample_id,
                sample_time,
                manual,
                transport_available,
                assertions_passed,
                status_code,
                configured_url,
                final_url,
                redirect_count,
                ttfb_ms,
                total_ms,
                error_kind
            FROM website_check_samples
            WHERE website_id = :website_id
            ORDER BY endpoint_id, sample_time DESC, sample_id DESC
            SQL
        );
        $statement->execute(['website_id' => $websiteId]);

        return array_map(fn (array $row): array => [
            'endpoint_id' => (int) $row['endpoint_id'],
            'sample_id' => (string) $row['sample_id'],
            'sample_time' => (string) $row['sample_time'],
            'manual' => $this->databaseBool($row['manual']),
            'transport_available' => $this->databaseBool($row['transport_available']),
            'assertions_passed' => $this->databaseBool($row['assertions_passed']),
            'status_code' => $row['status_code'] === null ? null : (int) $row['status_code'],
            'configured_url' => (string) $row['configured_url'],
            'final_url' => $row['final_url'] === null ? null : (string) $row['final_url'],
            'redirect_count' => (int) $row['redirect_count'],
            'ttfb_ms' => $this->nullableFloat($row['ttfb_ms']),
            'total_ms' => $this->nullableFloat($row['total_ms']),
            'error_kind' => $row['error_kind'] === null ? null : (string) $row['error_kind'],
        ], $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function diagnostics(int $websiteId, string $sampleId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT diagnostics
             FROM website_check_samples
             WHERE website_id = :website_id AND sample_id = :sample_id
             ORDER BY sample_time DESC
             LIMIT 1'
        );
        $statement->execute(['website_id' => $websiteId, 'sample_id' => $sampleId]);
        $json = $statement->fetchColumn();
        if ($json === false) {
            return null;
        }

        $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function safeDiagnosticArray(array $value): array
    {
        $safe = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::FORBIDDEN_DIAGNOSTIC_KEY, $key) === 1) {
                throw new InvalidArgumentException('Sensitive values are forbidden in website diagnostics.');
            }
            if (is_array($item)) {
                $item = $this->safeDiagnosticArray($item);
            } elseif (is_string($key) && str_contains(strtolower($key), 'url') && is_string($item)) {
                $item = $this->urlSanitizer->forDisplay($item);
            } elseif (!is_scalar($item) && $item !== null) {
                throw new InvalidArgumentException('Website diagnostic value is invalid.');
            }
            $safe[$key] = $item;
        }

        return $safe;
    }

    /** @param 'raw'|'hourly'|'daily' $source */
    private function bucketSeconds(int $duration, string $source): int
    {
        $minimum = match ($source) {
            'raw' => 60,
            'hourly' => 3600,
            'daily' => 86400,
        };
        $target = max($minimum, (int) ceil($duration / self::MAX_POINTS));
        foreach ([
            60, 300, 900, 1800, 3600, 7200, 14400, 21600, 43200,
            86400, 172800, 604800, 2592000,
        ] as $step) {
            if ($step >= $target && $step >= $minimum) {
                return $step;
            }
        }

        return (int) (ceil($target / 2592000) * 2592000);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function databaseBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
