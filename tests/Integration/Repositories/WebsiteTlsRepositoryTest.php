<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Domain\Websites\TlsInspectionResult;
use App\Repositories\WebsiteTlsRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebsiteTlsRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private WebsiteTlsRepository $repository;
    private int $websiteId;
    private int $endpointId;
    private DateTimeImmutable $now;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }
        $environment = [
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '5432'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'DB_SSLMODE' => (string) (getenv('TEST_DB_SSLMODE') ?: 'disable'),
        ];
        self::$pdo = ConnectionFactory::connect($environment);
        (new Migrator(self::pdo(), dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-28T00:00:00Z');
        $this->repository = new WebsiteTlsRepository(self::pdo());
        $this->websiteId = (int) self::pdo()->query(
            "INSERT INTO websites (name) VALUES ('TLS test') RETURNING id"
        )->fetchColumn();
        $endpoint = self::pdo()->prepare(
            <<<'SQL'
            INSERT INTO website_endpoints (
                website_id, name, url, is_primary, allow_self_signed, tls_expiry_enabled
            ) VALUES (
                :website_id, 'Primary', 'https://example.com/', TRUE, TRUE, TRUE
            ) RETURNING id
            SQL
        );
        $endpoint->execute(['website_id' => $this->websiteId]);
        $this->endpointId = (int) $endpoint->fetchColumn();
    }

    protected function tearDown(): void
    {
        self::pdo()->prepare('DELETE FROM websites WHERE id = :id')->execute(['id' => $this->websiteId]);
    }

    public function testSyncDeduplicatesTargetsAndKeepsSelfSignedOnlyForConfiguredHost(): void
    {
        $this->repository->syncTargets($this->endpointId, [
            ['hostname' => 'example.com', 'port' => 443, 'configured' => true],
            ['hostname' => 'example.com', 'port' => 443, 'configured' => false],
            ['hostname' => 'cdn.example.com', 'port' => 443, 'configured' => false],
        ]);

        $rows = self::pdo()->query(
            'SELECT hostname, source, allow_self_signed FROM website_tls_targets ORDER BY hostname'
        )->fetchAll();
        self::assertSame([
            ['hostname' => 'cdn.example.com', 'source' => 'redirect', 'allow_self_signed' => false],
            ['hostname' => 'example.com', 'source' => 'configured', 'allow_self_signed' => true],
        ], $rows);

        $this->repository->syncTargets($this->endpointId, [
            ['hostname' => 'example.com', 'port' => 443, 'configured' => true],
        ]);
        self::assertSame(1, (int) self::pdo()->query('SELECT count(*) FROM website_tls_targets')->fetchColumn());
    }

    public function testRecordSchedulesDailyAndCreatesEventOnlyWhenFingerprintChanges(): void
    {
        $this->repository->syncTargets($this->endpointId, [
            ['hostname' => 'example.com', 'port' => 443, 'configured' => true],
        ]);
        $targetId = (int) self::pdo()->query('SELECT id FROM website_tls_targets')->fetchColumn();

        $first = $this->repository->record($this->tlsResult($targetId, str_repeat('a', 64)));
        $changed = $this->repository->record($this->tlsResult($targetId, str_repeat('b', 64)));

        self::assertSame(['changed' => false, 'previous_fingerprint' => null], $first);
        self::assertSame(['changed' => true, 'previous_fingerprint' => str_repeat('a', 64)], $changed);
        self::assertSame(1, (int) self::pdo()->query('SELECT count(*) FROM website_certificate_events')->fetchColumn());
        self::assertSame(
            '2026-08-29T00:00:00+00',
            (string) self::pdo()->query(
                "SELECT to_char(next_check_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SSOF') FROM website_tls_targets"
            )->fetchColumn(),
        );
        self::assertSame([], $this->repository->dueTargets($this->now, 10));
        self::assertCount(1, $this->repository->dueTargets($this->now->modify('+1 day'), 10));
    }

    private function tlsResult(int $targetId, string $fingerprint): TlsInspectionResult
    {
        return new TlsInspectionResult(
            $this->endpointId,
            'example.com',
            443,
            $this->now,
            true,
            null,
            'CN=example.com',
            'CN=issuer',
            ['example.com'],
            $fingerprint,
            $this->now->modify('-1 day'),
            $this->now->modify('+30 days'),
            $targetId,
        );
    }

    private static function pdo(): PDO
    {
        return self::$pdo ?? throw new \LogicException('Test database is not connected.');
    }
}
