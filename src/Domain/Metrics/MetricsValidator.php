<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use DateTimeImmutable;
use JsonException;
use Throwable;

final class MetricsValidator
{
    private const TOP_LEVEL_FIELDS = [
        'version',
        'sample_id',
        'sample_time',
        'token',
        'metrics',
        'services',
        'process_snapshot',
        'agent_version',
        'os_version',
        'agent_artifact',
        'agent_capabilities',
        'probe_results',
    ];
    private const SERVICE_FIELDS = [
        'name',
        'status',
        'load_state',
        'active_state',
        'sub_state',
    ];
    private const SNAPSHOT_FIELDS = ['top_cpu', 'top_memory'];
    private const PROCESS_FIELDS = ['pid', 'name', 'command', 'value'];

    public function __construct(
        private readonly int $maxFutureSeconds = 300,
        private readonly int $maxAgeSeconds = 604800,
        private readonly int $maxSnapshotBytes = 65536
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function validate(
        array $payload,
        ?DateTimeImmutable $now = null
    ): MetricsEnvelope {
        $this->rejectUnknownFields($payload, self::TOP_LEVEL_FIELDS, 'unknown_field');

        if (($payload['version'] ?? null) !== 2) {
            throw new MetricsValidationException('unsupported_version');
        }

        $sampleId = $payload['sample_id'] ?? null;
        if (
            !is_string($sampleId)
            || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $sampleId
            ) !== 1
        ) {
            throw new MetricsValidationException('invalid_sample_id');
        }

        $sampleTimeRaw = $payload['sample_time'] ?? null;
        if (!is_string($sampleTimeRaw) || $sampleTimeRaw === '') {
            throw new MetricsValidationException('invalid_sample_time');
        }
        if (preg_match('/(?:Z|[+-]00:00)$/', $sampleTimeRaw) !== 1) {
            throw new MetricsValidationException('sample_time_not_utc');
        }

        try {
            $sampleTime = new DateTimeImmutable($sampleTimeRaw);
        } catch (Throwable) {
            throw new MetricsValidationException('invalid_sample_time');
        }
        if ($sampleTime->getOffset() !== 0) {
            throw new MetricsValidationException('sample_time_not_utc');
        }

        $now ??= new DateTimeImmutable();
        $clockDifference = $sampleTime->getTimestamp() - $now->getTimestamp();
        if ($clockDifference > $this->maxFutureSeconds) {
            throw new MetricsValidationException('sample_time_in_future');
        }
        if ($clockDifference < -$this->maxAgeSeconds) {
            throw new MetricsValidationException('sample_time_too_old');
        }

        $token = $payload['token'] ?? null;
        if (!is_string($token) || strlen($token) < 32 || strlen($token) > 512) {
            throw new MetricsValidationException('invalid_token');
        }

        $agentVersion = $this->agentVersion($payload['agent_version'] ?? null);
        $agentArtifact = $this->agentArtifact($payload['agent_artifact'] ?? null);
        $agentCapabilities = $this->agentCapabilities(
            $payload['agent_capabilities'] ?? []
        );

        $osVersion = $this->osVersion($payload['os_version'] ?? null);
        $probeResults = $this->probeResults($payload['probe_results'] ?? [], $now);
        $metrics = $this->metrics($payload['metrics'] ?? null, $probeResults !== []);
        $services = $this->services($payload['services'] ?? []);
        $snapshot = $this->processSnapshot($payload['process_snapshot'] ?? null);

        return new MetricsEnvelope(
            2,
            strtolower($sampleId),
            $sampleTime,
            $token,
            $metrics,
            $services,
            $snapshot,
            $agentVersion,
            $osVersion,
            $agentArtifact,
            $agentCapabilities,
            $probeResults
        );
    }

    /**
     * Optional so an agent released before this field keeps being accepted.
     */
    private function agentVersion(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (
            !is_string($value)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,31}$/', $value) !== 1
        ) {
            throw new MetricsValidationException('invalid_agent_version');
        }

        return $value;
    }

    private function agentArtifact(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (
            !is_string($value)
            || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $value) !== 1
        ) {
            throw new MetricsValidationException('invalid_agent_artifact');
        }

        return $value;
    }

    /** @return list<string> */
    private function agentCapabilities(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 16) {
            throw new MetricsValidationException('invalid_agent_capabilities');
        }
        $capabilities = [];
        foreach ($value as $capability) {
            if (
                !is_string($capability)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $capability) !== 1
                || isset($capabilities[$capability])
            ) {
                throw new MetricsValidationException('invalid_agent_capabilities');
            }
            $capabilities[$capability] = true;
        }

        return array_keys($capabilities);
    }

    /**
     * Optional so agents released before native platform reporting remain
     * protocol-compatible.
     */
    private function osVersion(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
        }
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > 255
            || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new MetricsValidationException('invalid_os_version');
        }

        return $value;
    }

    /** @return array<string, float> */
    private function metrics(mixed $value, bool $allowEmpty = false): array
    {
        if (!is_array($value) || ($value === [] && !$allowEmpty)) {
            throw new MetricsValidationException('invalid_metrics');
        }
        if ($value === []) {
            return [];
        }
        if (count($value) > 100) {
            throw new MetricsValidationException('too_many_metrics');
        }

        $metrics = [];
        foreach ($value as $name => $metricValue) {
            if (
                !is_string($name)
                || preg_match('/^[a-z][a-z0-9_]{0,99}$/', $name) !== 1
            ) {
                throw new MetricsValidationException('invalid_metric_name');
            }
            if (
                (!is_int($metricValue) && !is_float($metricValue))
                || !is_finite((float) $metricValue)
            ) {
                throw new MetricsValidationException('invalid_metric_value');
            }

            $metrics[$name] = (float) $metricValue;
        }

        return $metrics;
    }

    /**
     * @return list<array{
     *     name: string,
     *     status: 'running'|'stopped'|'unknown',
     *     load_state: string,
     *     active_state: string,
     *     sub_state: string
     * }>
     */
    private function services(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 500) {
            throw new MetricsValidationException('invalid_services');
        }

        $services = [];
        foreach ($value as $service) {
            if (!is_array($service) || array_is_list($service)) {
                throw new MetricsValidationException('invalid_service');
            }
            $this->rejectUnknownFields(
                $service,
                self::SERVICE_FIELDS,
                'unknown_service_field'
            );

            $name = $service['name'] ?? null;
            if (
                !is_string($name)
                || preg_match('/^[A-Za-z0-9_.@:-]{1,255}$/', $name) !== 1
            ) {
                throw new MetricsValidationException('invalid_service_name');
            }

            $status = $service['status'] ?? null;
            if (!in_array($status, ['running', 'stopped', 'unknown'], true)) {
                throw new MetricsValidationException('invalid_service_status');
            }

            $services[] = [
                'name' => $name,
                'status' => $status,
                'load_state' => $this->boundedString(
                    $service['load_state'] ?? '',
                    50,
                    'invalid_service_state'
                ),
                'active_state' => $this->boundedString(
                    $service['active_state'] ?? '',
                    50,
                    'invalid_service_state'
                ),
                'sub_state' => $this->boundedString(
                    $service['sub_state'] ?? '',
                    50,
                    'invalid_service_state'
                ),
            ];
        }

        return $services;
    }

    /**
     * @return array{
     *     top_cpu: list<array{pid: int, name: string, command: string, value: float}>,
     *     top_memory: list<array{pid: int, name: string, command: string, value: float}>
     * }|null
     */
    private function processSnapshot(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new MetricsValidationException('invalid_process_snapshot');
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MetricsValidationException('invalid_process_snapshot');
        }
        if (strlen($encoded) > $this->maxSnapshotBytes) {
            throw new MetricsValidationException('process_snapshot_too_large');
        }

        $this->rejectUnknownFields(
            $value,
            self::SNAPSHOT_FIELDS,
            'unknown_process_snapshot_field'
        );

        return [
            'top_cpu' => $this->processList($value['top_cpu'] ?? []),
            'top_memory' => $this->processList($value['top_memory'] ?? []),
        ];
    }

    /** @return list<array{pid: int, name: string, command: string, value: float}> */
    private function processList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw new MetricsValidationException('invalid_process_list');
        }

        $processes = [];
        foreach ($value as $process) {
            if (!is_array($process) || array_is_list($process)) {
                throw new MetricsValidationException('invalid_process');
            }
            $this->rejectUnknownFields(
                $process,
                self::PROCESS_FIELDS,
                'unknown_process_field'
            );

            $pid = filter_var(
                $process['pid'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $metricValue = $process['value'] ?? null;
            if (
                $pid === false
                || (!is_int($metricValue) && !is_float($metricValue))
                || !is_finite((float) $metricValue)
                || (float) $metricValue < 0
            ) {
                throw new MetricsValidationException('invalid_process');
            }

            $processes[] = [
                'pid' => $pid,
                'name' => $this->boundedString(
                    $process['name'] ?? null,
                    255,
                    'invalid_process'
                ),
                'command' => $this->boundedString(
                    $process['command'] ?? '',
                    512,
                    'invalid_process'
                ),
                'value' => (float) $metricValue,
            ];
        }

        return $processes;
    }

    /**
     * @return list<array{
     *   website_id:int,endpoint_id:int,observed_at:string,available:bool,
     *   status_code:?int,total_ms:float,error_kind:string,safe_message:string
     * }>
     */
    private function probeResults(mixed $value, DateTimeImmutable $now): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new MetricsValidationException('invalid_probe_results');
        }
        $allowed = [
            'website_id', 'endpoint_id', 'observed_at', 'available',
            'status_code', 'total_ms', 'error_kind', 'safe_message',
        ];
        $errorKinds = [
            '', 'dns', 'connect', 'timeout', 'tls',
            'redirect_loop', 'redirect_limit', 'redirect_scheme', 'internal_checker',
        ];
        $results = [];
        foreach ($value as $probe) {
            if (!is_array($probe) || array_is_list($probe)) {
                throw new MetricsValidationException('invalid_probe_result');
            }
            $this->rejectUnknownFields($probe, $allowed, 'invalid_probe_result');
            $websiteId = filter_var($probe['website_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $endpointId = filter_var($probe['endpoint_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $observedRaw = $probe['observed_at'] ?? null;
            if ($websiteId === false || $endpointId === false || !is_string($observedRaw)
                || preg_match('/(?:Z|[+-]00:00)$/', $observedRaw) !== 1
            ) {
                throw new MetricsValidationException('invalid_probe_result');
            }
            try {
                $observed = new DateTimeImmutable($observedRaw);
            } catch (Throwable) {
                throw new MetricsValidationException('invalid_probe_result');
            }
            $clockDifference = $observed->getTimestamp() - $now->getTimestamp();
            if ($observed->getOffset() !== 0
                || $clockDifference > $this->maxFutureSeconds
                || $clockDifference < -$this->maxAgeSeconds
            ) {
                throw new MetricsValidationException('invalid_probe_result');
            }
            $available = $probe['available'] ?? null;
            if (!is_bool($available)) {
                throw new MetricsValidationException('invalid_probe_result');
            }
            $statusCode = $probe['status_code'] ?? null;
            if ($statusCode !== null && (!is_int($statusCode) || $statusCode < 100 || $statusCode > 599)) {
                throw new MetricsValidationException('invalid_probe_result');
            }
            $totalMs = $probe['total_ms'] ?? null;
            if ((!is_int($totalMs) && !is_float($totalMs))) {
                throw new MetricsValidationException('invalid_probe_result');
            }
            $totalMs = (float) $totalMs;
            if (!is_finite($totalMs) || $totalMs < 0 || $totalMs > 120000) {
                throw new MetricsValidationException('invalid_probe_result');
            }
            $errorKind = $probe['error_kind'] ?? '';
            $safeMessage = $probe['safe_message'] ?? '';
            if (!is_string($errorKind) || !in_array($errorKind, $errorKinds, true)
                || !is_string($safeMessage) || mb_strlen($safeMessage) > 500
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $safeMessage) === 1
            ) {
                throw new MetricsValidationException('invalid_probe_result');
            }
            $results[] = [
                'website_id' => (int) $websiteId,
                'endpoint_id' => (int) $endpointId,
                'observed_at' => $observed->format('Y-m-d\TH:i:s\Z'),
                'available' => $available,
                'status_code' => $statusCode,
                'total_ms' => $totalMs,
                'error_kind' => $errorKind,
                'safe_message' => $safeMessage,
            ];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private function rejectUnknownFields(
        array $value,
        array $allowed,
        string $errorCode
    ): void {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new MetricsValidationException($errorCode);
        }
    }

    private function boundedString(mixed $value, int $maximum, string $errorCode): string
    {
        if (!is_string($value) || strlen($value) > $maximum) {
            throw new MetricsValidationException($errorCode);
        }

        return $value;
    }
}
