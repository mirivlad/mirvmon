<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Domain\Websites\WebsiteCheckError;
use App\Domain\Websites\WebsiteCheckResult;
use App\Repositories\WebsiteMetricsRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebsiteMetricsRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private WebsiteMetricsRepository $repository;
    private int $websiteId;
    private int $endpointId;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }

        self::$pdo = ConnectionFactory::connect([
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '5432'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'DB_SSLMODE' => (string) (getenv('TEST_DB_SSLMODE') ?: 'disable'),
        ]);
        (new Migrator(self::pdo(), dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        self::pdo()->beginTransaction();
        $this->websiteId = (int) self::pdo()->query(
            "INSERT INTO websites (name) VALUES ('Portal') RETURNING id"
        )->fetchColumn();
        $statement = self::pdo()->prepare(
            "INSERT INTO website_endpoints (website_id, name, url, is_primary)
             VALUES (:website_id, 'Home', 'https://example.com/', TRUE)
             RETURNING id"
        );
        $statement->execute(['website_id' => $this->websiteId]);
        $this->endpointId = (int) $statement->fetchColumn();
        $this->repository = new WebsiteMetricsRepository(self::pdo());
    }

    protected function tearDown(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    public function testRecordsSeparateRatiosAndTimingStatistics(): void
    {
        $this->repository->record($this->checkResult(
            sequence: 1,
            checkedAt: '2026-08-27T10:00:05Z',
            available: true,
            assertionsPassed: true,
            statusCode: 200,
            ttfbMs: 10.0,
            totalMs: 20.0,
        ));
        $this->repository->record($this->checkResult(
            sequence: 2,
            checkedAt: '2026-08-27T10:00:25Z',
            available: true,
            assertionsPassed: false,
            statusCode: 503,
            ttfbMs: 30.0,
            totalMs: 50.0,
            error: WebsiteCheckError::UnexpectedStatus,
        ));
        $this->repository->record($this->checkResult(
            sequence: 3,
            checkedAt: '2026-08-27T10:00:45Z',
            available: false,
            assertionsPassed: false,
            statusCode: null,
            ttfbMs: null,
            totalMs: null,
            error: WebsiteCheckError::Connect,
        ));

        $series = $this->repository->series(
            $this->websiteId,
            $this->endpointId,
            new DateTimeImmutable('2026-08-27T10:00:00Z'),
            new DateTimeImmutable('2026-08-27T10:01:00Z'),
        );

        self::assertSame('raw', $series['source']);
        self::assertSame(60, $series['bucket_seconds']);
        self::assertCount(1, $series['points']);
        $point = $series['points'][0];
        self::assertEqualsWithDelta(2 / 3, $point['transport_ratio'], 0.000001);
        self::assertEqualsWithDelta(1 / 3, $point['assertion_ratio'], 0.000001);
        self::assertSame(10.0, $point['ttfb_min_ms']);
        self::assertSame(20.0, $point['ttfb_avg_ms']);
        self::assertSame(30.0, $point['ttfb_max_ms']);
        self::assertSame(20.0, $point['total_min_ms']);
        self::assertSame(35.0, $point['total_avg_ms']);
        self::assertSame(50.0, $point['total_max_ms']);
        self::assertSame(3, $point['sample_count']);

        $latest = $this->repository->latest($this->websiteId);
        self::assertCount(1, $latest);
        self::assertFalse($latest[0]['transport_available']);
        self::assertFalse($latest[0]['assertions_passed']);
        self::assertNull($latest[0]['status_code']);
        self::assertSame('connect', $latest[0]['error_kind']);
    }

    public function testStoresRedirectDetailsOnlyWhenUsefulAndSanitizesUrls(): void
    {
        $first = $this->checkResult(
            sequence: 10,
            checkedAt: '2026-08-27T11:00:00Z',
            available: true,
            assertionsPassed: true,
            statusCode: 200,
            ttfbMs: 10.0,
            totalMs: 20.0,
            redirectChain: [[
                'url' => 'https://example.com/?access_token=secret',
                'status_code' => 301,
                'total_ms' => 5.0,
            ]],
            finalUrl: 'https://www.example.com/?signature=secret',
        );
        $this->repository->record($first);

        $storedFirst = $this->sample(10);
        self::assertSame(1, $storedFirst['redirect_count']);
        self::assertSame(
            'https://www.example.com/?signature=%5Bredacted%5D',
            $storedFirst['final_url']
        );
        $firstDiagnostics = $this->repository->diagnostics(
            $this->websiteId,
            $first->sampleId
        );
        self::assertSame(
            'https://example.com/?access_token=%5Bredacted%5D',
            $firstDiagnostics['redirect_chain'][0]['url'] ?? null
        );

        $stable = $this->checkResult(
            sequence: 11,
            checkedAt: '2026-08-27T11:01:00Z',
            available: true,
            assertionsPassed: true,
            statusCode: 200,
            ttfbMs: 11.0,
            totalMs: 21.0,
            redirectChain: [['url' => 'https://example.com/', 'status_code' => 301]],
            finalUrl: 'https://www.example.com/?signature=another-secret',
        );
        $this->repository->record($stable);
        self::assertArrayNotHasKey(
            'redirect_chain',
            $this->repository->diagnostics($this->websiteId, $stable->sampleId) ?? []
        );

        $manual = $this->checkResult(
            sequence: 12,
            checkedAt: '2026-08-27T11:02:00Z',
            available: true,
            assertionsPassed: true,
            statusCode: 200,
            ttfbMs: 12.0,
            totalMs: 22.0,
            redirectChain: [['url' => 'https://example.com/', 'status_code' => 301]],
            finalUrl: 'https://www.example.com/?signature=third-secret',
            manual: true,
        );
        $this->repository->record($manual);
        self::assertArrayHasKey(
            'redirect_chain',
            $this->repository->diagnostics($this->websiteId, $manual->sampleId) ?? []
        );
    }

    public function testRejectsSensitiveDiagnosticKeysBeforeInsert(): void
    {
        $result = $this->checkResult(
            sequence: 20,
            checkedAt: '2026-08-27T12:00:00Z',
            available: false,
            assertionsPassed: false,
            statusCode: null,
            ttfbMs: null,
            totalMs: null,
            error: WebsiteCheckError::Internal,
            redirectChain: [['url' => 'https://example.com/', 'headers' => ['secret']]],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->repository->record($result);
    }

    public function testSelectsRawHourlyAndDailySourcesAtContractBoundaries(): void
    {
        $end = new DateTimeImmutable('2026-08-27T00:00:00Z');

        self::assertSame('raw', $this->repository->sourceForRange($end->modify('-24 hours'), $end));
        self::assertSame('hourly', $this->repository->sourceForRange($end->modify('-7 days'), $end));
        self::assertSame('hourly', $this->repository->sourceForRange($end->modify('-30 days'), $end));
        self::assertSame('daily', $this->repository->sourceForRange($end->modify('-31 days'), $end));
    }

    public function testTimescalePoliciesMatchWebsiteRetentionContract(): void
    {
        $jobs = self::pdo()->query(
            "SELECT proc_name, hypertable_name, config
             FROM timescaledb_information.jobs
             WHERE hypertable_name IN (
                 'website_check_samples',
                 'website_check_samples_hourly',
                 'website_check_samples_daily'
             )"
        )->fetchAll();

        $retention = [];
        foreach ($jobs as $job) {
            if ($job['proc_name'] === 'policy_retention') {
                $config = json_decode((string) $job['config'], true, 512, JSON_THROW_ON_ERROR);
                $retention[(string) $job['hypertable_name']] = $config['drop_after'] ?? null;
            }
        }

        self::assertSame('30 days', $retention['website_check_samples'] ?? null);
        self::assertSame('365 days', $retention['website_check_samples_hourly'] ?? null);
        self::assertArrayNotHasKey('website_check_samples_daily', $retention);
    }

    public function testSchemaNeverStoresResponseBodies(): void
    {
        $columns = self::pdo()->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'website_check_samples'"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertNotContains('body', $columns);
        self::assertNotContains('response_body', $columns);
        self::assertContains('diagnostics', $columns);
    }

    /** @param list<array<string, mixed>> $redirectChain */
    private function checkResult(
        int $sequence,
        string $checkedAt,
        bool $available,
        bool $assertionsPassed,
        ?int $statusCode,
        ?float $ttfbMs,
        ?float $totalMs,
        ?WebsiteCheckError $error = null,
        array $redirectChain = [],
        string $finalUrl = 'https://example.com/',
        bool $manual = false,
    ): WebsiteCheckResult {
        return new WebsiteCheckResult(
            websiteId: $this->websiteId,
            endpointId: $this->endpointId,
            sampleId: sprintf('20000000-0000-4000-8000-%012d', $sequence),
            checkedAt: new DateTimeImmutable($checkedAt),
            transportAvailable: $available,
            assertionsPassed: $assertionsPassed,
            statusCode: $statusCode,
            configuredUrl: 'https://example.com/?api_key=secret',
            finalUrl: $finalUrl,
            redirectChain: $redirectChain,
            timings: [
                'dns_ms' => $ttfbMs === null ? null : 1.0,
                'tcp_ms' => $ttfbMs === null ? null : 2.0,
                'tls_ms' => $ttfbMs === null ? null : 3.0,
                'ttfb_ms' => $ttfbMs,
                'total_ms' => $totalMs,
            ],
            error: $error,
            assertionResults: $assertionsPassed ? [] : [[
                'kind' => 'status',
                'passed' => false,
                'safe_message' => 'Configured assertion did not pass.',
            ]],
            manual: $manual,
        );
    }

    /** @return array<string, mixed> */
    private function sample(int $sequence): array
    {
        $statement = self::pdo()->prepare(
            'SELECT * FROM website_check_samples WHERE sample_id = :sample_id'
        );
        $statement->execute([
            'sample_id' => sprintf('20000000-0000-4000-8000-%012d', $sequence),
        ]);

        $row = $statement->fetch();
        self::assertIsArray($row);

        return $row;
    }

    private static function pdo(): PDO
    {
        return self::$pdo ?? throw new \LogicException('Integration database is not connected.');
    }
}
