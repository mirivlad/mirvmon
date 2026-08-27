<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Websites;

use App\Domain\Websites\WebsiteCheckError;
use App\Domain\Websites\WebsiteCheckResult;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WebsiteCheckResultTest extends TestCase
{
    public function testResultSeparatesAvailabilityFromAssertions(): void
    {
        $result = new WebsiteCheckResult(
            websiteId: 7,
            endpointId: 9,
            sampleId: '10000000-0000-4000-8000-000000000009',
            checkedAt: new DateTimeImmutable('2026-08-27T00:00:00Z'),
            transportAvailable: true,
            assertionsPassed: false,
            statusCode: 503,
            configuredUrl: 'https://example.com/',
            finalUrl: 'https://example.com/',
            redirectChain: [],
            timings: [
                'dns_ms' => 1.0,
                'tcp_ms' => 2.0,
                'tls_ms' => 3.0,
                'ttfb_ms' => 20.0,
                'total_ms' => 25.0,
            ],
            error: WebsiteCheckError::UnexpectedStatus,
            assertionResults: [[
                'kind' => 'status',
                'passed' => false,
                'safe_message' => 'Expected status was not received.',
            ]],
            manual: false,
        );

        self::assertTrue($result->transportAvailable);
        self::assertFalse($result->assertionsPassed);
        self::assertSame(WebsiteCheckError::UnexpectedStatus, $result->error);
        self::assertSame('app', $result->probeKind);
        self::assertNull($result->probeId);
    }
}
