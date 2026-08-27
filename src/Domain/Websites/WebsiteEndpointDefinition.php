<?php

declare(strict_types=1);

namespace App\Domain\Websites;

final readonly class WebsiteEndpointDefinition
{
    /**
     * @param list<array{kind: string, selector: ?string, needle: string}> $contentChecks
     * @param array<string, string> $headers
     * @param list<string> $credentialRedirectHosts
     */
    public function __construct(
        public string $name,
        public string $url,
        public string $method,
        public int $intervalSeconds,
        public int $timeoutSeconds,
        public bool $followRedirects,
        public int $maxRedirects,
        public bool $statusCheckEnabled,
        public ExpectedStatusSet $expectedStatuses,
        public array $contentChecks,
        public ?int $warningTotalMs,
        public ?int $criticalTotalMs,
        public string $authType,
        public ?string $authUsername,
        public ?string $authSecret,
        public array $headers,
        public array $credentialRedirectHosts,
        public bool $allowSelfSigned,
        public bool $tlsExpiryEnabled,
    ) {
    }
}
