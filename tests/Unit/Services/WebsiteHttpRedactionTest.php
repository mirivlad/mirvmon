<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\Websites\ExpectedStatusSet;
use App\Domain\Websites\WebsiteEndpointDefinition;
use App\Services\WebsiteHttpChecker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WebsiteHttpRedactionTest extends TestCase
{
    public function testCrossOriginRequestHasNoSensitiveHeadersWithoutAllowList(): void
    {
        $headers = $this->outgoingHeaders(
            $this->endpoint([]),
            'https://other.example/health',
            'https://configured.example',
        );

        self::assertSame([], $headers);
    }

    public function testExplicitRedirectOriginReceivesConfiguredSensitiveHeaders(): void
    {
        $headers = $this->outgoingHeaders(
            $this->endpoint(['https://other.example']),
            'https://other.example/health',
            'https://configured.example',
        );

        self::assertContains('X-Api-Key: test-value', $headers);
        self::assertContains('Authorization: Bearer test-token', $headers);
    }

    /** @param list<string> $allowList */
    private function endpoint(array $allowList): WebsiteEndpointDefinition
    {
        return new WebsiteEndpointDefinition(
            name: 'endpoint', url: 'https://configured.example/', method: 'GET', intervalSeconds: 60,
            timeoutSeconds: 15, followRedirects: true, maxRedirects: 10, statusCheckEnabled: false,
            expectedStatuses: ExpectedStatusSet::fromString('200-299'), contentChecks: [], warningTotalMs: null,
            criticalTotalMs: null, authType: 'bearer', authUsername: null, authSecret: 'test-token',
            headers: ['X-Api-Key' => 'test-value'], credentialRedirectHosts: $allowList,
            allowSelfSigned: false, tlsExpiryEnabled: false,
        );
    }

    /** @return list<string> */
    private function outgoingHeaders(WebsiteEndpointDefinition $endpoint, string $url, string $configuredOrigin): array
    {
        $method = new ReflectionMethod(WebsiteHttpChecker::class, 'outgoingHeaders');
        $method->setAccessible(true);

        return $method->invoke(new WebsiteHttpChecker(), $endpoint, $url, $configuredOrigin);
    }
}
