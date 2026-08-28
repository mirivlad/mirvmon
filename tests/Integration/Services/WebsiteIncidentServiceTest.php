<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Domain\Websites\WebsiteCheckError;
use App\Domain\Websites\WebsiteCheckResult;
use App\Domain\Websites\DomainRegistrationResult;
use App\Domain\Websites\TlsInspectionResult;
use App\Repositories\IncidentRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\WebsiteMetricsRepository;
use App\Services\WebsiteIncidentService;
use App\Services\WebsiteStateEvaluator;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebsiteIncidentServiceTest extends TestCase
{
    private static ?PDO $pdo = null;
    private WebsiteIncidentService $service;
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
        (new Migrator(self::$pdo, dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        self::$pdo?->beginTransaction();
        $groupId = (int) self::$pdo?->query(
            "INSERT INTO monitoring_groups (name) VALUES ('Website tests') RETURNING id"
        )->fetchColumn();
        $this->websiteId = (int) self::$pdo?->query(
            "INSERT INTO websites (group_id, name) VALUES ({$groupId}, 'Portal') RETURNING id"
        )->fetchColumn();
        $this->endpointId = (int) self::$pdo?->query(
            "INSERT INTO website_endpoints (website_id, name, url, is_primary, status_check_enabled)
             VALUES ({$this->websiteId}, 'Home', 'https://example.com/', TRUE, TRUE)
             RETURNING id"
        )->fetchColumn();
        self::$pdo?->prepare(
            'INSERT INTO website_endpoint_state (endpoint_id, website_id) VALUES (:endpoint_id, :website_id)'
        )->execute(['endpoint_id' => $this->endpointId, 'website_id' => $this->websiteId]);
        self::$pdo?->prepare(
            'INSERT INTO website_state (website_id, primary_endpoint_id) VALUES (:website_id, :endpoint_id)'
        )->execute(['website_id' => $this->websiteId, 'endpoint_id' => $this->endpointId]);
        $this->service = new WebsiteIncidentService(
            self::$pdo,
            new WebsiteStateEvaluator(),
            new WebsiteMetricsRepository(self::$pdo),
            new NotificationOutboxRepository(self::$pdo),
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testThreeFailuresCreateOneWebsiteIncidentAndTwoSuccessesResolveIt(): void
    {
        $this->service->recordHttp($this->makeResult(false, '00:00:00'));
        $this->service->recordHttp($this->makeResult(false, '00:01:00'));
        $this->service->recordHttp($this->makeResult(false, '00:02:00'));

        $active = (new IncidentRepository(self::$pdo))->active(['website_id' => $this->websiteId]);
        self::assertCount(1, $active);
        self::assertSame('website', $active[0]['source_type']);
        self::assertSame('Portal', $active[0]['source_name']);
        self::assertSame('website_http', $active[0]['kind']);
        self::assertSame('2026-08-27 00:00:00+00', $active[0]['created_at']);

        $this->service->recordHttp($this->makeResult(true, '00:03:00'));
        self::assertCount(1, (new IncidentRepository(self::$pdo))->active(['website_id' => $this->websiteId]));
        $this->service->recordHttp($this->makeResult(true, '00:04:00'));

        self::assertCount(0, (new IncidentRepository(self::$pdo))->active(['website_id' => $this->websiteId]));
        self::assertSame(2, (int) self::$pdo?->query(
            "SELECT count(*) FROM website_availability_events WHERE website_id = {$this->websiteId}"
        )->fetchColumn());
    }

    public function testWebsiteNotificationPayloadIncludesWebsiteName(): void
    {
        self::$pdo?->exec(
            "UPDATE notification_settings
             SET telegram_enabled = TRUE, telegram_chat_id = '-100', notify_on_critical = TRUE
             WHERE id = 1"
        );

        $this->service->recordHttp($this->makeResult(false, '00:00:00'));
        $this->service->recordHttp($this->makeResult(false, '00:01:00'));
        $this->service->recordHttp($this->makeResult(false, '00:02:00'));

        $payload = self::$pdo?->query(
            "SELECT payload::text FROM notification_outbox
             WHERE website_id = {$this->websiteId} AND event_type = 'website_http_triggered'
             ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertIsString($payload);
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Portal', $decoded['website_name'] ?? null);
        self::assertSame('Home', $decoded['endpoint_name'] ?? null);
    }

    public function testPauseClosesActiveWebsiteIncidentWithoutRecoveryNotification(): void
    {
        $this->service->recordHttp($this->makeResult(false, '00:00:00'));
        $this->service->recordHttp($this->makeResult(false, '00:01:00'));
        $this->service->recordHttp($this->makeResult(false, '00:02:00'));
        $this->service->pause($this->websiteId, new DateTimeImmutable('2026-08-27T00:03:00+00:00'));

        $row = self::$pdo?->query(
            "SELECT resolved, resolution_reason FROM alerts WHERE website_id = {$this->websiteId}"
        )->fetch();
        self::assertTrue(in_array($row['resolved'], [true, 1, '1', 't'], true));
        self::assertSame('monitoring_paused', $row['resolution_reason']);
        self::assertSame('paused', self::$pdo?->query(
            "SELECT status FROM website_state WHERE website_id = {$this->websiteId}"
        )->fetchColumn());
    }

    public function testTlsAndDomainProblemsUseIndependentWebsiteIncidentKinds(): void
    {
        $targetId = (int) self::$pdo?->query(
            "INSERT INTO website_tls_targets (website_id, endpoint_id, hostname, port, source)
             VALUES ({$this->websiteId}, {$this->endpointId}, 'example.com', 443, 'configured') RETURNING id"
        )->fetchColumn();
        $this->service->recordTls(new TlsInspectionResult(
            endpointId: $this->endpointId,
            hostname: 'example.com',
            port: 443,
            checkedAt: new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            valid: false,
            errorKind: 'hostname_mismatch',
            subject: null,
            issuer: null,
            sans: [],
            fingerprintSha256: null,
            notBefore: null,
            notAfter: null,
            targetId: $targetId,
        ));
        $this->service->recordDomain($this->websiteId, new DomainRegistrationResult(
            domain: 'example.com',
            checkedAt: new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
            status: 'supported',
            expiresAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            registrar: 'Registrar',
            source: 'rdap',
            errorKind: null,
        ));

        $kinds = self::$pdo?->query(
            "SELECT kind FROM alerts WHERE website_id = {$this->websiteId} ORDER BY kind"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['website_domain', 'website_tls'], $kinds);
    }

    private function makeResult(bool $available, string $time): WebsiteCheckResult
    {
        return new WebsiteCheckResult(
            websiteId: $this->websiteId,
            endpointId: $this->endpointId,
            sampleId: '10000000-0000-4000-8000-' . substr(md5($time . (string) $available), 0, 12),
            checkedAt: new DateTimeImmutable('2026-08-27T' . $time . '+00:00'),
            transportAvailable: $available,
            assertionsPassed: $available,
            statusCode: $available ? 200 : null,
            configuredUrl: 'https://example.com/',
            finalUrl: $available ? 'https://example.com/' : null,
            redirectChain: [],
            timings: ['dns_ms' => 1.0, 'tcp_ms' => 1.0, 'tls_ms' => 1.0, 'ttfb_ms' => 5.0, 'total_ms' => 10.0],
            error: $available ? null : WebsiteCheckError::Connect,
            assertionResults: [],
            manual: false,
        );
    }
}
