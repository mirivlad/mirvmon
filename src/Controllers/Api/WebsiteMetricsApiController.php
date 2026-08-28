<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Repositories\IncidentRepository;
use App\Repositories\WebsiteMetricsRepository;
use App\Repositories\WebsiteRepository;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class WebsiteMetricsApiController
{
    /** @var array<string, string> */
    private const PERIODS = [
        '1h' => '-1 hour', '6h' => '-6 hours', '24h' => '-24 hours',
        '7d' => '-7 days', '30d' => '-30 days', '365d' => '-365 days',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebsiteRepository $websites,
        private readonly WebsiteMetricsRepository $metrics,
        private readonly IncidentRepository $incidents,
    ) {
    }

    /** @param array<string, string> $args */
    public function metrics(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        if ($websiteId === null || $this->websites->find($websiteId) === null) {
            return $this->error($response, 404, 'website_not_found');
        }
        $query = $request->getQueryParams();
        $endpointId = null;
        if (($query['endpoint_id'] ?? '') !== '') {
            $endpointId = $this->positiveId($query['endpoint_id']);
            if ($endpointId === null || !$this->endpointBelongs($websiteId, $endpointId)) {
                return $this->error($response, 422, 'invalid_endpoint');
            }
        }
        try {
            [$from, $to, $period] = $this->range($query);
            $series = $this->metrics->series($websiteId, $endpointId, $from, $to);
            return $this->json($response, [
                'period' => $period,
                'start' => $from->format(DateTimeInterface::ATOM),
                'end' => $to->format(DateTimeInterface::ATOM),
                'source' => $series['source'],
                'bucket_seconds' => $series['bucket_seconds'],
                'series' => $this->series($series['points']),
                'incidents' => $this->incidents($websiteId, $from, $to),
                'availability_intervals' => $this->availability($websiteId, $endpointId, $from, $to),
            ]);
        } catch (InvalidArgumentException) {
            return $this->error($response, 422, 'invalid_time_range');
        } catch (Throwable) {
            return $this->error($response, 500, 'metrics_unavailable');
        }
    }

    /** @param array<string, string> $args */
    public function status(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        if ($websiteId === null) {
            return $this->error($response, 404, 'website_not_found');
        }
        $website = $this->websites->detail($websiteId);
        if ($website === null) {
            return $this->error($response, 404, 'website_not_found');
        }
        $latest = [];
        foreach ($this->metrics->latest($websiteId) as $sample) {
            $latest[] = [
                'endpoint_id' => $sample['endpoint_id'],
                'sample_time' => $sample['sample_time'],
                'transport_available' => $sample['transport_available'],
                'assertions_passed' => $sample['assertions_passed'],
                'status_code' => $sample['status_code'],
                'ttfb_ms' => $sample['ttfb_ms'],
                'total_ms' => $sample['total_ms'],
                'error_kind' => $sample['error_kind'],
            ];
        }
        $state = $this->state($websiteId);
        return $this->json($response, [
            'website_id' => $websiteId,
            'status' => $state['status'],
            'updated_at' => $state['updated_at'],
            'endpoints' => $latest,
        ]);
    }

    /**
     * @param list<array{endpoint_id:int,time_bucket:string,transport_ratio:float,assertion_ratio:float,ttfb_avg_ms:?float,total_avg_ms:?float}> $points
     * @return array{transport_availability:list<array{time:string,endpoint_id:int,value:float}>,assertion_success:list<array{time:string,endpoint_id:int,value:float}>,ttfb_ms:list<array{time:string,endpoint_id:int,value:?float}>,total_ms:list<array{time:string,endpoint_id:int,value:?float}>}
     */
    private function series(array $points): array
    {
        $result = [
            'transport_availability' => [], 'assertion_success' => [],
            'ttfb_ms' => [], 'total_ms' => [],
        ];
        foreach ($points as $point) {
            $base = ['time' => $point['time_bucket'], 'endpoint_id' => $point['endpoint_id']];
            $result['transport_availability'][] = [...$base, 'value' => $point['transport_ratio']];
            $result['assertion_success'][] = [...$base, 'value' => $point['assertion_ratio']];
            $result['ttfb_ms'][] = [...$base, 'value' => $point['ttfb_avg_ms']];
            $result['total_ms'][] = [...$base, 'value' => $point['total_avg_ms']];
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function incidents(int $websiteId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $filters = [
            'website_id' => $websiteId,
            'from' => $from->format(DateTimeInterface::ATOM),
            'to' => $to->format(DateTimeInterface::ATOM),
        ];
        $rows = [...$this->incidents->active($filters), ...$this->incidents->history($filters)];
        $result = [];
        foreach ($rows as $row) {
            $start = (string) ($row['created_at'] ?? '');
            if ($start === '') {
                continue;
            }
            $result[] = [
                'id' => $row['id'] ?? $row['source_id'] ?? null,
                'kind' => (string) ($row['kind'] ?? ''),
                'severity' => (string) ($row['severity'] ?? ''),
                'start' => $start,
                'end' => $row['resolved_at'] ?? null,
                'endpoint_id' => $row['endpoint_id'] ?? null,
            ];
        }
        return $result;
    }

    /** @return list<array{state:string,start:string,end:?string,alert_id:?int}> */
    private function availability(int $websiteId, ?int $endpointId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $filter = $endpointId === null ? '' : 'AND endpoint_id = :endpoint_id';
        $statement = $this->pdo->prepare(
            "SELECT state, occurred_at, alert_id,
                    LEAD(state) OVER (PARTITION BY endpoint_id ORDER BY occurred_at, id) AS next_state,
                    LEAD(occurred_at) OVER (PARTITION BY endpoint_id ORDER BY occurred_at, id) AS next_at
             FROM website_availability_events
             WHERE website_id = :website_id AND occurred_at <= :range_end
               {$filter} ORDER BY occurred_at, id"
        );
        $params = ['website_id' => $websiteId, 'range_end' => $to->format(DateTimeInterface::ATOM)];
        if ($endpointId !== null) $params['endpoint_id'] = $endpointId;
        $statement->execute($params);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if ($row['state'] !== 'unavailable' || ($row['next_state'] ?? null) === 'available') continue;
            $result[] = [
                'state' => 'unavailable', 'start' => max($from->format(DateTimeInterface::ATOM), (string) $row['occurred_at']),
                'end' => $row['next_at'] === null ? null : (string) $row['next_at'],
                'alert_id' => $row['alert_id'] === null ? null : (int) $row['alert_id'],
            ];
        }
        return $result;
    }

    /** @return array{status:string,updated_at:?string} */
    private function state(int $websiteId): array
    {
        $statement = $this->pdo->prepare('SELECT status, updated_at FROM website_state WHERE website_id = :id');
        $statement->execute(['id' => $websiteId]);
        $row = $statement->fetch();
        return is_array($row) ? ['status' => (string) $row['status'], 'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at']] : ['status' => 'no_data', 'updated_at' => null];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable,2:string}
     */
    private function range(array $query): array
    {
        $period = is_string($query['period'] ?? null) ? $query['period'] : '24h';
        $to = new DateTimeImmutable();
        if ($period === 'custom') {
            try {
                $from = new DateTimeImmutable((string) ($query['from'] ?? ''));
                $to = new DateTimeImmutable((string) ($query['to'] ?? ''));
            } catch (Throwable) {
                throw new InvalidArgumentException('Invalid custom website metric range.');
            }
        } elseif (isset(self::PERIODS[$period])) {
            $from = $to->modify(self::PERIODS[$period]);
        } else {
            throw new InvalidArgumentException('Invalid website metric period.');
        }
        if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > 63072000) {
            throw new InvalidArgumentException('Invalid website metric range.');
        }
        return [$from, $to, $period];
    }

    private function endpointBelongs(int $websiteId, int $endpointId): bool
    {
        foreach (($this->websites->detail($websiteId)['endpoints'] ?? []) as $endpoint) {
            if ((int) ($endpoint['id'] ?? 0) === $endpointId) return true;
        }
        return false;
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function error(Response $response, int $status, string $code): Response
    {
        return $this->json($response, ['error' => $code], $status);
    }
}
