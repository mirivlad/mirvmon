<?php

declare(strict_types=1);

namespace App\Domain\Websites;

use DateTimeImmutable;

final readonly class WebsiteCheckResult
{
    /**
     * @param list<array<string, mixed>> $redirectChain
     * @param array{
     *     dns_ms: ?float,
     *     tcp_ms: ?float,
     *     tls_ms: ?float,
     *     ttfb_ms: ?float,
     *     total_ms: ?float
     * } $timings
     * @param list<array{kind: string, passed: bool, safe_message: string}> $assertionResults
     */
    public function __construct(
        public int $websiteId,
        public int $endpointId,
        public string $sampleId,
        public DateTimeImmutable $checkedAt,
        public bool $transportAvailable,
        public bool $assertionsPassed,
        public ?int $statusCode,
        public string $configuredUrl,
        public ?string $finalUrl,
        public array $redirectChain,
        public array $timings,
        public ?WebsiteCheckError $error,
        public array $assertionResults,
        public bool $manual,
        public string $probeKind = 'app',
        public ?string $probeId = null,
    ) {
    }
}
