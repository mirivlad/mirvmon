<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\WebsiteCheckQueueRepository;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebsiteCheckQueueRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private WebsiteCheckQueueRepository $queue;
    private int $websiteId;
    private int $firstEndpointId;
    private int $secondEndpointId;
    private int $tlsTargetId;
    private DateTimeImmutable $now;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }

        self::$pdo = ConnectionFactory::connect(self::databaseEnvironment());
        (new Migrator(self::pdo(), dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-27T00:00:00Z');
        $this->queue = new WebsiteCheckQueueRepository(self::pdo());
        $this->websiteId = $this->createWebsite();
        $this->firstEndpointId = $this->createEndpoint(
            'Primary',
            'https://example.com/',
            30,
            true
        );
        $this->secondEndpointId = $this->createEndpoint(
            'Fallback',
            'http://example.com/health',
            null,
            false
        );
        $this->tlsTargetId = $this->createTlsTarget($this->firstEndpointId);
    }

    protected function tearDown(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }

        if ($this->websiteId > 0) {
            $statement = self::pdo()->prepare('DELETE FROM websites WHERE id = :id');
            $statement->execute(['id' => $this->websiteId]);
        }
    }

    public function testScheduleDueEnqueuesOneCatchUpJobPerTargetAndAdvancesDeadlines(): void
    {
        self::assertSame(4, $this->queue->scheduleDue($this->now));

        $jobs = $this->jobs();
        self::assertCount(4, $jobs);
        self::assertSame(['domain', 'http', 'http', 'tls'], array_column($jobs, 'kind'));
        self::assertSame(0, $this->queue->scheduleDue($this->now->modify('+1 second')));

        self::assertSame(
            '2026-08-27T00:00:30+00:00',
            $this->timestamp('website_endpoints', 'next_http_check_at', $this->firstEndpointId)
        );
        self::assertSame(
            '2026-08-27T00:02:00+00:00',
            $this->timestamp('website_endpoints', 'next_http_check_at', $this->secondEndpointId)
        );
        self::assertSame(
            '2026-08-28T00:00:00+00:00',
            $this->timestamp('website_tls_targets', 'next_check_at', $this->tlsTargetId)
        );
        self::assertSame(
            '2026-08-28T00:00:00+00:00',
            $this->timestamp('websites', 'domain_next_check_at', $this->websiteId)
        );
    }

    public function testScheduleDueDoesNotEnqueuePausedWebsite(): void
    {
        self::pdo()->prepare('UPDATE websites SET is_active = FALSE WHERE id = :id')
            ->execute(['id' => $this->websiteId]);

        self::assertSame(0, $this->queue->scheduleDue($this->now));
        self::assertSame([], $this->jobs());
    }

    public function testScheduleLimitFairlyIncludesTlsAndDomainDespiteHttpBacklog(): void
    {
        for ($index = 0; $index < 4; $index++) {
            $this->createEndpoint(
                'Backlog ' . $index,
                'https://example.com/backlog/' . $index,
                30,
                false
            );
        }

        self::assertSame(2, $this->queue->scheduleDue($this->now, 2));
        self::assertCount(2, $this->jobs());
        self::assertSame(['domain', 'tls'], array_column($this->jobs(), 'kind'));

        self::assertSame(2, $this->queue->scheduleDue($this->now->modify('+1 second'), 2));
        self::assertCount(4, $this->jobs());
        self::assertSame(
            ['domain' => 1, 'http' => 2, 'tls' => 1],
            array_count_values(array_column($this->jobs(), 'kind'))
        );
    }

    public function testManualJobsCoalesceAndSortBeforeScheduledJobs(): void
    {
        self::assertSame(4, $this->queue->scheduleDue($this->now));
        self::assertSame(4, $this->queue->enqueueManual($this->websiteId, $this->now));
        self::assertSame(0, $this->queue->enqueueManual($this->websiteId, $this->now));

        $claimed = $this->queue->claim('worker-a', $this->now, 1);
        self::assertSame(
            0,
            $this->queue->enqueueManual($this->websiteId, $this->now->modify('+1 second'))
        );

        self::assertCount(1, $claimed);
        self::assertTrue($claimed[0]['manual']);
        self::assertSame(100, $claimed[0]['priority']);
    }

    public function testManualEnqueueTimesOutRatherThanOmittingLockedTarget(): void
    {
        $locker = $this->connection();
        $locker->beginTransaction();

        try {
            $lock = $locker->prepare(
                'SELECT id FROM website_endpoints WHERE id = :id FOR UPDATE'
            );
            $lock->execute(['id' => $this->firstEndpointId]);
            self::assertSame($this->firstEndpointId, (int) $lock->fetchColumn());

            $startedAt = microtime(true);
            try {
                $this->queue->enqueueManual($this->websiteId, $this->now);
                self::fail('Manual enqueue silently omitted a locked target.');
            } catch (PDOException $exception) {
                self::assertSame('55P03', $exception->getCode());
                self::assertLessThan(5.0, microtime(true) - $startedAt);
            }

            self::assertSame([], $this->jobs());
        } finally {
            if ($locker->inTransaction()) {
                $locker->rollBack();
            }
        }
    }

    public function testTwoClaimersNeverReceiveTheSameJob(): void
    {
        self::assertSame(4, $this->queue->scheduleDue($this->now));

        $coordinator = $this->connection();
        $lockKey = random_int(1, PHP_INT_MAX);
        $process = null;
        $pipes = [];

        try {
            $this->advisoryLock($coordinator, $lockKey, true);
            [$process, $pipes] = $this->startClaimProcess($lockKey);

            self::pdo()->beginTransaction();
            self::pdo()->exec("SET LOCAL statement_timeout = '3s'");
            self::pdo()->exec("SET LOCAL lock_timeout = '2s'");
            $first = $this->queue->claim('worker-a', $this->now, 10);

            $this->advisoryLock($coordinator, $lockKey, false);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $pipes = [];
            $exitCode = proc_close($process);
            $process = null;

            self::assertSame(0, $exitCode, $stderr);
            $second = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($second);
            self::assertCount(4, $first);
            self::assertSame([], array_values(array_intersect(
                array_column($first, 'id'),
                array_column($second, 'id')
            )));
        } finally {
            $this->advisoryLock($coordinator, $lockKey, false);
            if (self::pdo()->inTransaction()) {
                self::pdo()->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }
    }

    public function testExpiredLeaseIsReclaimedByAnotherWorker(): void
    {
        self::assertSame(4, $this->queue->enqueueManual($this->websiteId, $this->now));
        $claimed = $this->queue->claim('worker-a', $this->now, 1);

        $reclaimed = (new WebsiteCheckQueueRepository($this->connection()))
            ->claim('worker-b', $this->now->modify('+61 seconds'), 1);

        self::assertCount(1, $claimed);
        self::assertCount(1, $reclaimed);
        self::assertSame($claimed[0]['id'], $reclaimed[0]['id']);
        self::assertSame('worker-b', $reclaimed[0]['lease_owner']);
        self::assertSame(2, $reclaimed[0]['attempts']);
    }

    public function testReleaseUsesProvidedRetryTimeAndRetainsOnlySafeErrorCategory(): void
    {
        $this->queue->enqueueManual($this->websiteId, $this->now);
        $leaseNow = $this->databaseNow();
        $job = $this->queue->claim('worker-a', $leaseNow, 1)[0];
        $availableAt = $leaseNow->modify('+2 seconds');

        $this->queue->release((int) $job['id'], 'worker-a', $availableAt, 'timeout');

        $released = $this->job((int) $job['id']);
        self::assertSame('pending', $released['state']);
        self::assertSame('timeout', $released['safe_error_kind']);
        self::assertSame(
            $availableAt->format(DateTimeInterface::ATOM),
            (new DateTimeImmutable((string) $released['available_at']))->format(DateTimeInterface::ATOM)
        );
        self::assertNull($released['lease_owner']);
        self::assertNull($released['lease_until']);
    }

    public function testCompleteRejectsAnExpiredLease(): void
    {
        $this->queue->enqueueManual($this->websiteId, $this->now);
        $job = $this->queue->claim('worker-a', $this->databaseNow(), 1)[0];
        self::pdo()->prepare(
            "UPDATE website_check_jobs
             SET lease_until = clock_timestamp() - INTERVAL '1 second'
             WHERE id = :id"
        )->execute(['id' => $job['id']]);

        try {
            $this->queue->complete((int) $job['id'], 'worker-a');
            self::fail('Completion accepted an expired lease.');
        } catch (RuntimeException $exception) {
            self::assertSame('Website check lease is not owned or has expired.', $exception->getMessage());
        }

        self::assertNotNull($this->jobOrNull((int) $job['id']));
    }

    public function testReleaseRejectsAnExpiredLease(): void
    {
        $this->queue->enqueueManual($this->websiteId, $this->now);
        $leaseNow = $this->databaseNow();
        $job = $this->queue->claim('worker-a', $leaseNow, 1)[0];
        self::pdo()->prepare(
            "UPDATE website_check_jobs
             SET lease_until = clock_timestamp() - INTERVAL '1 second'
             WHERE id = :id"
        )->execute(['id' => $job['id']]);

        try {
            $this->queue->release(
                (int) $job['id'],
                'worker-a',
                $leaseNow->modify('+1 minute'),
                'timeout'
            );
            self::fail('Release accepted an expired lease.');
        } catch (RuntimeException $exception) {
            self::assertSame('Website check lease is not owned or has expired.', $exception->getMessage());
        }

        self::assertSame('leased', $this->job((int) $job['id'])['state']);
    }

    public function testAttemptCapPreventsAnEleventhClaimAndCompleteDeletesOwnedJob(): void
    {
        $this->queue->enqueueManual($this->websiteId, $this->now);
        $leaseNow = $this->databaseNow();
        $job = $this->queue->claim('worker-a', $leaseNow, 1)[0];

        self::pdo()->prepare(
            "UPDATE website_check_jobs
             SET attempts = 10, state = 'leased', lease_owner = 'worker-a',
                 lease_until = :lease_until
             WHERE id = :id"
        )->execute([
            'id' => $job['id'],
            'lease_until' => $leaseNow->modify('+60 seconds')->format(DateTimeInterface::ATOM),
        ]);
        $this->queue->release(
            (int) $job['id'],
            'worker-a',
            $leaseNow->modify('+1 hour'),
            'timeout'
        );
        $remaining = $this->queue->claim('worker-b', $leaseNow->modify('+2 hours'), 10);
        self::assertNotContains((int) $job['id'], array_column($remaining, 'id'));

        $next = $remaining[0];
        $this->queue->complete((int) $next['id'], 'worker-b');
        self::assertNull($this->jobOrNull((int) $next['id']));
    }

    private function createWebsite(): int
    {
        $statement = self::pdo()->prepare(
            <<<'SQL'
            INSERT INTO websites (
                name, registration_domain, domain_check_enabled,
                default_interval_seconds, domain_next_check_at
            ) VALUES (
                'Example', 'example.com', TRUE, 120, :domain_next_check_at
            )
            RETURNING id
            SQL
        );
        $statement->execute([
            'domain_next_check_at' => $this->now->modify('-1 day')->format(DateTimeInterface::ATOM),
        ]);

        return (int) $statement->fetchColumn();
    }

    private function createEndpoint(
        string $name,
        string $url,
        ?int $intervalSeconds,
        bool $tlsExpiryEnabled,
    ): int {
        $statement = self::pdo()->prepare(
            <<<'SQL'
            INSERT INTO website_endpoints (
                website_id, name, url, is_primary, interval_seconds,
                tls_expiry_enabled, next_http_check_at
            ) VALUES (
                :website_id, :name, :url, :is_primary, :interval_seconds,
                :tls_expiry_enabled, :next_http_check_at
            )
            RETURNING id
            SQL
        );
        $statement->execute([
            'website_id' => $this->websiteId,
            'name' => $name,
            'url' => $url,
            'is_primary' => $name === 'Primary' ? 1 : 0,
            'interval_seconds' => $intervalSeconds,
            'tls_expiry_enabled' => $tlsExpiryEnabled ? 1 : 0,
            'next_http_check_at' => $this->now->modify('-1 day')->format(DateTimeInterface::ATOM),
        ]);

        return (int) $statement->fetchColumn();
    }

    private function createTlsTarget(int $endpointId): int
    {
        $statement = self::pdo()->prepare(
            <<<'SQL'
            INSERT INTO website_tls_targets (
                website_id, endpoint_id, hostname, port, source, next_check_at
            ) VALUES (
                :website_id, :endpoint_id, 'example.com', 443, 'configured', :next_check_at
            )
            RETURNING id
            SQL
        );
        $statement->execute([
            'website_id' => $this->websiteId,
            'endpoint_id' => $endpointId,
            'next_check_at' => $this->now->modify('-1 day')->format(DateTimeInterface::ATOM),
        ]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function jobs(): array
    {
        return self::pdo()->query(
            'SELECT id, kind, manual, priority FROM website_check_jobs ORDER BY kind, id'
        )->fetchAll();
    }

    /** @return array<string, mixed> */
    private function job(int $id): array
    {
        return $this->jobOrNull($id) ?? throw new \LogicException('Website check job does not exist.');
    }

    /** @return array<string, mixed>|null */
    private function jobOrNull(int $id): ?array
    {
        $statement = self::pdo()->prepare('SELECT * FROM website_check_jobs WHERE id = :id');
        $statement->execute(['id' => $id]);
        $job = $statement->fetch();

        return is_array($job) ? $job : null;
    }

    private function timestamp(string $table, string $column, int $id): string
    {
        $statement = self::pdo()->prepare(sprintf('SELECT %s FROM %s WHERE id = :id', $column, $table));
        $statement->execute(['id' => $id]);

        return (new DateTimeImmutable((string) $statement->fetchColumn()))->format(DateTimeInterface::ATOM);
    }

    private function connection(): PDO
    {
        return ConnectionFactory::connect(self::databaseEnvironment());
    }

    private function databaseNow(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) self::pdo()->query('SELECT clock_timestamp()')->fetchColumn());
    }

    private function advisoryLock(PDO $pdo, int $lockKey, bool $acquire): void
    {
        $statement = $pdo->prepare(sprintf(
            'SELECT pg_advisory_%s(:lock_key)',
            $acquire ? 'lock' : 'unlock'
        ));
        $statement->execute(['lock_key' => $lockKey]);
    }

    /** @return array{0:resource,1:array<int,resource>} */
    private function startClaimProcess(int $lockKey): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $claimAt = $this->now->format(DateTimeInterface::ATOM);
        $code = sprintf(
            <<<'PHP'
            require %s;

            $pdo = App\Database\ConnectionFactory::connect([
                'DB_HOST' => (string) getenv('TEST_DB_HOST'),
                'DB_PORT' => (string) getenv('TEST_DB_PORT'),
                'DB_NAME' => (string) getenv('TEST_DB_NAME'),
                'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
                'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
                'DB_SSLMODE' => (string) getenv('TEST_DB_SSLMODE'),
            ]);
            $pdo->exec("SET statement_timeout = '3s'");
            $pdo->exec("SET lock_timeout = '2s'");
            $gate = $pdo->prepare('SELECT pg_advisory_lock(:lock_key)');
            $gate->execute(['lock_key' => %d]);
            try {
                $jobs = (new App\Repositories\WebsiteCheckQueueRepository($pdo))->claim(
                    'worker-b',
                    new DateTimeImmutable(%s),
                    10
                );
                fwrite(STDOUT, json_encode($jobs, JSON_THROW_ON_ERROR));
            } finally {
                $gate = $pdo->prepare('SELECT pg_advisory_unlock(:lock_key)');
                $gate->execute(['lock_key' => %d]);
            }
            PHP,
            var_export($autoload, true),
            $lockKey,
            var_export($claimAt, true),
            $lockKey
        );
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 3)
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start overlapping queue claimer.');
        }
        fclose($pipes[0]);
        unset($pipes[0]);

        return [$process, $pipes];
    }

    /** @return array<string, string> */
    private static function databaseEnvironment(): array
    {
        return [
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '5432'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'DB_SSLMODE' => (string) (getenv('TEST_DB_SSLMODE') ?: 'disable'),
        ];
    }

    private static function pdo(): PDO
    {
        return self::$pdo ?? throw new \LogicException('Integration database is not connected.');
    }
}
