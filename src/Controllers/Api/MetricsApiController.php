<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Repositories\MetricRepository;
use DateTimeImmutable;
use JsonException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class MetricsApiController
{
    private const PERIODS = [
        '1h' => '-1 hour',
        '6h' => '-6 hours',
        '24h' => '-24 hours',
        '7d' => '-7 days',
        '30d' => '-30 days',
        '90d' => '-90 days',
        '1y' => '-1 year',
    ];

    public function __construct(private readonly MetricRepository $metrics)
    {
    }

    /**
     * @throws JsonException
     */
    public function getServerMetrics(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = filter_var(
            $args['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($serverId === false) {
            return $this->jsonError($response, 400, 'invalid_server_id');
        }

        $query = $request->getQueryParams();
        $period = is_string($query['period'] ?? null) ? $query['period'] : '7d';
        if (!isset(self::PERIODS[$period])) {
            return $this->jsonError($response, 422, 'invalid_period');
        }

        try {
            $end = isset($query['end']) && is_string($query['end'])
                ? new DateTimeImmutable($query['end'])
                : new DateTimeImmutable();
            $start = isset($query['start']) && is_string($query['start'])
                ? new DateTimeImmutable($query['start'])
                : $end->modify(self::PERIODS[$period]);
        } catch (Throwable) {
            return $this->jsonError($response, 422, 'invalid_time_range');
        }

        $duration = $end->getTimestamp() - $start->getTimestamp();
        if ($duration <= 0 || $duration > 63072000) {
            return $this->jsonError($response, 422, 'invalid_time_range');
        }

        $metricNames = array_column($this->metrics->metricTypes($serverId), 'name');
        $series = $this->metrics->series($serverId, $start, $end, $metricNames);

        /** @var array<string, string> $units */
        $units = [];
        /** @var array<string, array<string, float>> $valuesByMetric */
        $valuesByMetric = [];
        /** @var array<string, true> $timestamps */
        $timestamps = [];

        foreach ($series['points'] as $point) {
            $timestamp = $point['time_bucket'];
            $timestamps[$timestamp] = true;
            $units[$point['name']] = $point['unit'] ?? '';
            $valuesByMetric[$point['name']][$timestamp] = $point['value'];
        }

        $labels = array_keys($timestamps);
        sort($labels);
        $datasets = [];
        foreach ($valuesByMetric as $name => $values) {
            $datasets[$name] = array_map(
                static fn (string $timestamp): ?float => $values[$timestamp] ?? null,
                $labels
            );
        }

        return $this->json($response, [
            'period' => $period,
            'start' => $start->format(DATE_ATOM),
            'end' => $end->format(DATE_ATOM),
            'source' => $series['source'],
            'aggregation_seconds' => $series['bucket_seconds'],
            'total_points' => count($labels),
            'labels' => $labels,
            'datasets' => $datasets,
            'units' => $units,
            'top_cpu' => [],
            'top_ram' => [],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(Response $response, int $status, string $code): Response
    {
        return $this->json($response, ['error' => $code], $status);
    }
}
