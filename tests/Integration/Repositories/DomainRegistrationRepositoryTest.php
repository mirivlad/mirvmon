<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Domain\Websites\DomainRegistrationResult;
use App\Repositories\DomainRegistrationRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class DomainRegistrationRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private DomainRegistrationRepository $repository;
    private int $websiteId;
    private DateTimeImmutable $now;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }
        $environment = [
            'DB_HOST' => (string) getenv('TEST_DB_HOST'), 'DB_PORT' => (string) getenv('TEST_DB_PORT'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'), 'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'), 'DB_SSLMODE' => (string) getenv('TEST_DB_SSLMODE'),
        ];
        self::$pdo = ConnectionFactory::connect($environment);
        (new Migrator(self::pdo(), dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-28T00:00:00Z');
        $this->repository = new DomainRegistrationRepository(self::pdo());
        $this->websiteId = (int) self::pdo()->query(
            "INSERT INTO websites (name, registration_domain, domain_check_enabled, domain_next_check_at) VALUES ('Domain test', 'example.com', TRUE, '2026-08-27T00:00:00Z') RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        self::pdo()->prepare('DELETE FROM websites WHERE id = :id')->execute(['id' => $this->websiteId]);
    }

    public function testSupportedAndUnsupportedResultsScheduleDailyWithoutRawPayload(): void
    {
        $this->repository->record($this->websiteId, $this->registrationResult('supported', 'rdap', null));

        $state = self::pdo()->query('SELECT status, expires_at, source, error_kind FROM website_domain_state')->fetch();
        self::assertSame('healthy', $state['status']);
        self::assertSame('rdap', $state['source']);
        self::assertNull($state['error_kind']);
        self::assertSame([], $this->repository->dueWebsites($this->now, 10));
        self::assertCount(1, $this->repository->dueWebsites($this->now->modify('+1 day'), 10));

        $this->repository->record($this->websiteId, $this->registrationResult('unsupported', null, 'unsupported_zone'));
        self::assertSame('unsupported', self::pdo()->query('SELECT status FROM website_domain_state')->fetchColumn());
    }

    public function testRateLimitAndUnknownUseSafeBoundedBackoff(): void
    {
        $this->repository->record($this->websiteId, new DomainRegistrationResult(
            'example.com', $this->now, 'unknown', null, null, 'rdap', 'rate_limited', 200000,
        ));
        self::assertSame([], $this->repository->dueWebsites($this->now->modify('+23 hours'), 10));
        self::assertCount(1, $this->repository->dueWebsites($this->now->modify('+24 hours'), 10));

        $this->repository->record($this->websiteId, $this->registrationResult('unknown', 'rdap', 'rdap_malformed'));
        self::assertSame([], $this->repository->dueWebsites($this->now->modify('+119 seconds'), 10));
        self::assertCount(1, $this->repository->dueWebsites($this->now->modify('+120 seconds'), 10));
    }

    private function registrationResult(string $status, ?string $source, ?string $error): DomainRegistrationResult
    {
        return new DomainRegistrationResult(
            'example.com', $this->now, $status,
            $status === 'supported' ? $this->now->modify('+30 days') : null,
            $status === 'supported' ? 'Registrar' : null,
            $source,
            $error,
        );
    }

    private static function pdo(): PDO
    {
        return self::$pdo ?? throw new \LogicException('Test database is not connected.');
    }
}
