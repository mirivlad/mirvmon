<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Domain\Websites\ExpectedStatusSet;
use App\Domain\Websites\WebsiteEndpointDefinition;
use App\Services\WebsiteHttpChecker;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebsiteHttpFixture;
use Tests\Support\WebsiteTlsFixture;

final class WebsiteHttpCheckerTest extends TestCase
{
    private WebsiteHttpFixture $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = new WebsiteHttpFixture();
        $this->fixtures->start();
    }

    protected function tearDown(): void
    {
        $this->fixtures->stop();
    }

    public function testDefaultRedirectReachesFinalUrlAndRetainsConfiguredUrl(): void
    {
        $configuredUrl = $this->fixtures->firstOrigin('/redirect/start');

        $result = (new WebsiteHttpChecker())->check($this->definition($configuredUrl), 1, 1);

        self::assertTrue($result->transportAvailable, $result->error?->value ?? 'no error');
        self::assertTrue($result->assertionsPassed);
        self::assertSame($configuredUrl, $result->configuredUrl);
        self::assertSame($this->fixtures->firstOrigin('/ok'), $result->finalUrl);
        self::assertCount(2, $result->redirectChain);
    }

    public function testDisabledRedirectEvaluatesTheFirstRedirectResponse(): void
    {
        $result = (new WebsiteHttpChecker())->check(
            $this->definition($this->fixtures->firstOrigin('/redirect/start'), followRedirects: false, statuses: '301'),
            1,
            1,
        );

        self::assertTrue($result->transportAvailable);
        self::assertTrue($result->assertionsPassed);
        self::assertSame(301, $result->statusCode);
        self::assertSame([], $result->redirectChain);
    }

    public function testRedirectLoopAndLimitHaveDifferentErrorCategories(): void
    {
        $checker = new WebsiteHttpChecker();
        $loop = $checker->check($this->definition($this->fixtures->firstOrigin('/loop-a')), 1, 1);
        $limit = $checker->check(
            $this->definition($this->fixtures->firstOrigin('/redirect/start'), maxRedirects: 1),
            1,
            1,
        );

        self::assertFalse($loop->transportAvailable);
        self::assertSame('redirect_loop', $loop->error?->value);
        self::assertFalse($limit->transportAvailable);
        self::assertSame('redirect_limit', $limit->error?->value);
    }

    public function testStatusAndContentFailuresRemainTransportAvailable(): void
    {
        $checker = new WebsiteHttpChecker();
        $status = $checker->check(
            $this->definition($this->fixtures->firstOrigin('/status/503'), statuses: '200-299'),
            1,
            1,
        );
        $content = $checker->check(
            $this->definition($this->fixtures->firstOrigin('/ok'), contentChecks: [['kind' => 'page_text', 'selector' => null, 'needle' => 'missing']]),
            1,
            1,
        );

        self::assertTrue($status->transportAvailable);
        self::assertFalse($status->assertionsPassed);
        self::assertSame('unexpected_status', $status->error?->value);
        self::assertTrue($content->transportAvailable);
        self::assertFalse($content->assertionsPassed);
        self::assertSame('content_missing', $content->error?->value);
    }

    public function testSensitiveHeadersAreRemovedAcrossOriginUnlessExplicitlyAllowed(): void
    {
        $target = $this->fixtures->secondOrigin('/headers');
        $start = $this->fixtures->firstOrigin('/redirect-to?target=' . rawurlencode($target));
        $checker = new WebsiteHttpChecker();

        $removed = $checker->check($this->definition($start, bearer: 'secret', headers: ['X-Api-Key' => 'hidden']), 1, 1);
        $allowed = $checker->check(
            $this->definition(
                $start,
                statuses: '201',
                bearer: 'secret',
                headers: ['X-Api-Key' => 'hidden'],
                credentialRedirectHosts: [$this->fixtures->secondOrigin()],
            ),
            1,
            1,
        );

        self::assertTrue($removed->transportAvailable);
        self::assertSame(200, $removed->statusCode);
        self::assertTrue($allowed->transportAvailable);
        self::assertSame(201, $allowed->statusCode);
    }

    public function testLargeResponseAndTimeoutHaveDistinctErrorCategories(): void
    {
        $checker = new WebsiteHttpChecker();
        $large = $checker->check($this->definition($this->fixtures->firstOrigin('/large')), 1, 1);
        $timeout = $checker->check(
            $this->definition($this->fixtures->firstOrigin('/slow'), timeoutSeconds: 0),
            1,
            1,
        );

        self::assertSame('response_too_large', $large->error?->value);
        self::assertFalse($large->transportAvailable);
        self::assertSame('timeout', $timeout->error?->value);
        self::assertFalse($timeout->transportAvailable);
    }

    public function testSuccessfulResultHasNonNegativeTimingMetrics(): void
    {
        $result = (new WebsiteHttpChecker())->check($this->definition($this->fixtures->firstOrigin('/redirect/start')), 1, 1);

        foreach ($result->timings as $value) {
            self::assertGreaterThanOrEqual(0.0, $value);
        }
        self::assertLessThanOrEqual($result->timings['total_ms'], $result->timings['ttfb_ms']);
    }

    public function testCheckManyRunsIndependentRequestsWithinConfiguredConcurrency(): void
    {
        $startedAt = microtime(true);
        $results = (new WebsiteHttpChecker())->checkMany([
            [
                'definition' => $this->definition($this->fixtures->firstOrigin('/slow')),
                'website_id' => 1,
                'endpoint_id' => 1,
                'manual' => false,
            ],
            [
                'definition' => $this->definition($this->fixtures->secondOrigin('/slow')),
                'website_id' => 2,
                'endpoint_id' => 2,
                'manual' => true,
            ],
        ], 2);

        self::assertCount(2, $results);
        self::assertTrue($results[0]->transportAvailable);
        self::assertTrue($results[1]->transportAvailable);
        self::assertTrue($results[1]->manual);
        self::assertLessThan(0.45, microtime(true) - $startedAt);
    }

    public function testCheckManyContinuesRedirectsWithoutLosingPerCheckState(): void
    {
        $results = (new WebsiteHttpChecker())->checkMany([
            [
                'definition' => $this->definition($this->fixtures->firstOrigin('/redirect/start')),
                'website_id' => 1,
                'endpoint_id' => 1,
                'manual' => false,
            ],
            [
                'definition' => $this->definition($this->fixtures->secondOrigin('/redirect/start')),
                'website_id' => 2,
                'endpoint_id' => 2,
                'manual' => true,
            ],
        ], 2);

        self::assertCount(2, $results);
        self::assertSame($this->fixtures->firstOrigin('/ok'), $results[0]->finalUrl);
        self::assertSame($this->fixtures->secondOrigin('/ok'), $results[1]->finalUrl);
        self::assertCount(2, $results[0]->redirectChain);
        self::assertTrue($results[1]->manual);
    }

    public function testSelfSignedModeOnlyRelaxesTheConfiguredHttpsEndpoint(): void
    {
        $tls = new WebsiteTlsFixture();
        $certificate = dirname(__DIR__, 2) . '/Fixtures/Websites/certs/valid-self-signed.pem';
        $port = $tls->start($certificate);

        try {
            $url = 'https://localhost:' . $port . '/';
            $strict = (new WebsiteHttpChecker())->check($this->definition($url), 1, 1);
            $allowed = (new WebsiteHttpChecker())->check(
                $this->definition($url, allowSelfSigned: true),
                1,
                1,
            );
            $many = (new WebsiteHttpChecker())->checkMany([[
                'definition' => $this->definition($url),
                'website_id' => 1,
                'endpoint_id' => 1,
                'manual' => false,
            ]], 1);

            self::assertFalse($strict->transportAvailable);
            self::assertSame('tls', $strict->error?->value);
            self::assertTrue($allowed->transportAvailable, $allowed->error?->value ?? 'missing error');
            self::assertSame(200, $allowed->statusCode);
            self::assertCount(1, $many);
            self::assertSame('tls', $many[0]->error?->value);
        } finally {
            $tls->stop();
        }
    }

    /** @param list<array{kind:string,selector:?string,needle:string}> $contentChecks
     * @param array<string,string> $headers
     * @param list<string> $credentialRedirectHosts */
    private function definition(
        string $url,
        bool $followRedirects = true,
        int $maxRedirects = 10,
        string $statuses = '200-299',
        array $contentChecks = [],
        ?string $bearer = null,
        array $headers = [],
        array $credentialRedirectHosts = [],
        int $timeoutSeconds = 2,
        bool $allowSelfSigned = false,
    ): WebsiteEndpointDefinition
    {
        return new WebsiteEndpointDefinition(
            name: 'Test endpoint',
            url: $url,
            method: 'GET',
            intervalSeconds: 60,
            timeoutSeconds: $timeoutSeconds,
            followRedirects: $followRedirects,
            maxRedirects: $maxRedirects,
            statusCheckEnabled: true,
            expectedStatuses: ExpectedStatusSet::fromString($statuses),
            contentChecks: $contentChecks,
            warningTotalMs: null,
            criticalTotalMs: null,
            authType: $bearer === null ? 'none' : 'bearer',
            authUsername: null,
            authSecret: $bearer,
            headers: $headers,
            credentialRedirectHosts: $credentialRedirectHosts,
            allowSelfSigned: $allowSelfSigned,
            tlsExpiryEnabled: false,
        );
    }
}
