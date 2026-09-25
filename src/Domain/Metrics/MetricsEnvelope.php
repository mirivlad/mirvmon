<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use DateTimeImmutable;

final readonly class MetricsEnvelope
{
    /**
     * @param array<string, float> $metrics
     * @param list<array{
     *     name: string,
     *     status: 'running'|'stopped'|'unknown',
     *     load_state: string,
     *     active_state: string,
     *     sub_state: string
     * }> $services
     * @param array{
     *     top_cpu: list<array{pid: int, name: string, command: string, value: float}>,
     *     top_memory: list<array{pid: int, name: string, command: string, value: float}>
     * }|null $processSnapshot
     * @param list<string> $agentCapabilities
     * @param list<array{
     *   website_id:int,endpoint_id:int,observed_at:string,available:bool,
     *   status_code:?int,total_ms:float,error_kind:string,safe_message:string
     * }> $probeResults
     */
    public function __construct(
        public int $version,
        public string $sampleId,
        public DateTimeImmutable $sampleTime,
        public string $token,
        public array $metrics,
        public array $services,
        public ?array $processSnapshot,
        public ?string $agentVersion = null,
        public ?string $osVersion = null,
        public ?string $agentArtifact = null,
        public array $agentCapabilities = [],
        public array $probeResults = []
    ) {
    }
}
