<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\Metrics\AgentAuthenticationException;
use App\Domain\Metrics\MetricsValidationException;
use App\Domain\Metrics\MetricsValidator;
use App\Services\MetricsIngestionService;
use DateTimeImmutable;
use JsonException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class MetricsController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MetricsValidator $validator,
        private readonly MetricsIngestionService $ingestion
    ) {
    }

    /** @param array<string, string> $args */
    public function collectMetrics(
        Request $request,
        Response $response,
        array $args
    ): Response {
        try {
            $payload = json_decode(
                (string) $request->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return $this->jsonError($response, 400, 'invalid_json');
        }

        if (!is_array($payload) || array_is_list($payload)) {
            return $this->jsonError($response, 422, 'invalid_payload');
        }

        try {
            $envelope = $this->validator->validate($payload);
        } catch (MetricsValidationException $exception) {
            return $this->jsonError($response, 422, $exception->errorCode);
        }

        try {
            $result = $this->ingestion->ingest($envelope);
        } catch (AgentAuthenticationException) {
            return $this->jsonError($response, 401, 'invalid_token');
        }

        return $this->json(
            $response,
            [
                'accepted' => true,
                'duplicate' => $result->duplicate,
            ],
            $result->duplicate ? 200 : 202
        );
    }

    /** @param array<string, string> $args */
    public function getServices(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->jsonError($response, 400, 'invalid_server_id');
        }

        $statement = $this->pdo->prepare(
            'SELECT
                service_name,
                status,
                load_state,
                active_state,
                sub_state,
                updated_at
             FROM service_status
             WHERE server_id = :server_id
             ORDER BY service_name'
        );
        $statement->execute(['server_id' => $serverId]);

        return $this->json($response, ['services' => $statement->fetchAll()]);
    }

    /** @param array<string, string> $args */
    public function getProcesses(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->jsonError($response, 400, 'invalid_server_id');
        }

        $timeValue = $request->getQueryParams()['time'] ?? null;
        if (!is_string($timeValue) || $timeValue === '') {
            return $this->jsonError($response, 400, 'time_required');
        }

        try {
            $time = new DateTimeImmutable($timeValue);
        } catch (Throwable) {
            return $this->jsonError($response, 422, 'invalid_time');
        }

        $statement = $this->pdo->prepare(
            "SELECT sample_time, processes
             FROM process_snapshots
             WHERE server_id = :server_id
               AND sample_time BETWEEN
                    CAST(:range_start AS timestamptz) - INTERVAL '30 seconds'
                    AND CAST(:range_end AS timestamptz) + INTERVAL '30 seconds'
             ORDER BY ABS(
                EXTRACT(EPOCH FROM (
                    sample_time - CAST(:target_time AS timestamptz)
                ))
             )
             LIMIT 1"
        );
        $timestamp = $time->format('Y-m-d H:i:s.uP');
        $statement->execute([
            'server_id' => $serverId,
            'range_start' => $timestamp,
            'range_end' => $timestamp,
            'target_time' => $timestamp,
        ]);
        $snapshot = $statement->fetch();

        if (!is_array($snapshot)) {
            return $this->json($response, [
                'top_cpu' => [],
                'top_ram' => [],
                'time' => $time->format(DATE_ATOM),
            ]);
        }

        try {
            $processes = json_decode(
                (string) $snapshot['processes'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            $processes = [];
        }
        if (!is_array($processes)) {
            $processes = [];
        }

        return $this->json($response, [
            'top_cpu' => is_array($processes['top_cpu'] ?? null)
                ? $processes['top_cpu']
                : [],
            'top_ram' => is_array($processes['top_memory'] ?? null)
                ? $processes['top_memory']
                : [],
            'time' => (string) $snapshot['sample_time'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(
        Response $response,
        array $payload,
        int $status = 200
    ): Response {
        $response->getBody()->write(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(
        Response $response,
        int $status,
        string $code
    ): Response {
        return $this->json(
            $response,
            ['error' => ['code' => $code]],
            $status
        );
    }

    /** @param array<string, mixed> $args */
    private function serverId(array $args): ?int
    {
        $serverId = filter_var(
            $args['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $serverId === false ? null : $serverId;
    }
}
