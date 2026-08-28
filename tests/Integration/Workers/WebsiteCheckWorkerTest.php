<?php

declare(strict_types=1);

namespace Tests\Integration\Workers;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Domain\Websites\WebsiteCheckResult;
use App\Repositories\DomainRegistrationRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\WebsiteCheckQueueRepository;
use App\Repositories\WebsiteMetricsRepository;
use App\Repositories\WebsiteTlsRepository;
use App\Services\WebsiteIncidentService;
use App\Services\WebsiteProbeExecutor;
use App\Services\WebsiteStateEvaluator;
use App\Workers\WebsiteCheckWorker;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebsiteCheckWorkerTest extends TestCase
{
    private static ?PDO $pdo = null;
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
        self::$pdo?->exec('DELETE FROM website_check_jobs');
        self::$pdo?->exec("UPDATE website_endpoints SET next_http_check_at = CURRENT_TIMESTAMP + INTERVAL '1 day'");
        $group = (int) self::$pdo?->query("INSERT INTO monitoring_groups (name) VALUES ('Worker tests') RETURNING id")->fetchColumn();
        $this->websiteId = (int) self::$pdo?->query("INSERT INTO websites (group_id, name) VALUES ({$group}, 'Worker site') RETURNING id")->fetchColumn();
        $this->endpointId = (int) self::$pdo?->query("INSERT INTO website_endpoints (website_id, name, url, is_primary, status_check_enabled) VALUES ({$this->websiteId}, 'Home', 'https://example.com/', TRUE, TRUE) RETURNING id")->fetchColumn();
        self::$pdo?->prepare('INSERT INTO website_endpoint_state (endpoint_id, website_id) VALUES (:endpoint, :website)')->execute(['endpoint' => $this->endpointId, 'website' => $this->websiteId]);
        self::$pdo?->prepare('INSERT INTO website_state (website_id, primary_endpoint_id) VALUES (:website, :endpoint)')->execute(['website' => $this->websiteId, 'endpoint' => $this->endpointId]);
        self::$pdo?->prepare("UPDATE website_endpoints SET next_http_check_at = '2026-08-27T00:00:00Z' WHERE id = :id")->execute(['id' => $this->endpointId]);
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testWorkerPerformsProbeOutsideDatabaseTransactionAndPersistsResult(): void
    {
        $probe = new class(self::$pdo, $this->websiteId, $this->endpointId) implements WebsiteProbeExecutor {
            public bool $transactionWasOpen = true;
            public function __construct(private ?PDO $pdo, private int $websiteId, private int $endpointId) {}
            public function execute(array $jobs, int $concurrency): array
            {
                $this->transactionWasOpen = $this->pdo?->inTransaction() ?? true;
                return [[
                    'job_id' => (int) $jobs[0]['id'],
                    'result' => new WebsiteCheckResult(
                        $this->websiteId, $this->endpointId, '10000000-0000-4000-8000-000000000001',
                        new DateTimeImmutable('2026-08-27T00:00:00Z'), true, true, 200,
                        'https://example.com/', 'https://example.com/', [],
                        ['dns_ms' => 1.0, 'tcp_ms' => 1.0, 'tls_ms' => 1.0, 'ttfb_ms' => 2.0, 'total_ms' => 3.0],
                        null, [], false,
                    ),
                ]];
            }
        };
        $service = new WebsiteIncidentService(
            self::$pdo,
            new WebsiteStateEvaluator(),
            new WebsiteMetricsRepository(self::$pdo),
            new NotificationOutboxRepository(self::$pdo),
        );
        $worker = new WebsiteCheckWorker(
            new WebsiteCheckQueueRepository(self::$pdo),
            $probe,
            $service,
            new WebsiteTlsRepository(self::$pdo),
            new DomainRegistrationRepository(self::$pdo),
            'worker-test',
            10,
        );

        self::assertSame(1, $worker->runOnce(new DateTimeImmutable('now')));
        self::assertFalse($probe->transactionWasOpen);
        self::assertSame(1, (int) self::$pdo?->query("SELECT count(*) FROM website_check_samples WHERE website_id = {$this->websiteId}")->fetchColumn());
        self::assertSame(0, (int) self::$pdo?->query("SELECT count(*) FROM website_check_jobs WHERE website_id = {$this->websiteId}")->fetchColumn());
    }
}
