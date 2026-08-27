<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class WebsiteCheckQueueRepository
{
    private const LEASE_SECONDS = 60;
    private const MAX_ATTEMPTS = 10;
    private const MANUAL_PRIORITY = 100;
    private const SCHEDULE_METHODS = ['scheduleTls', 'scheduleDomain', 'scheduleHttp'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function scheduleDue(DateTimeImmutable $now, int $limit = 100): int
    {
        $this->assertLimit($limit);

        return $this->transaction('website_check_schedule', function () use ($now, $limit): int {
            $cursor = $this->pdo->query(
                'SELECT next_kind FROM website_check_schedule_cursor WHERE id = 1 FOR UPDATE'
            )->fetchColumn();
            if ($cursor === false) {
                throw new RuntimeException('Website check schedule cursor is missing.');
            }
            $offset = (int) $cursor;
            $baseQuota = intdiv($limit, 3);
            $remainder = $limit % 3;
            $quotas = array_fill(0, 3, $baseQuota);
            for ($index = 0; $index < $remainder; $index++) {
                $quotas[($offset + $index) % 3]++;
            }
            $scheduled = 0;

            foreach (self::SCHEDULE_METHODS as $index => $schedule) {
                $quota = $quotas[$index];
                if ($quota > 0) {
                    $scheduled += $this->{$schedule}($now, $quota);
                }
            }

            for ($index = 0; $index < 3 && $scheduled < $limit; $index++) {
                $schedule = self::SCHEDULE_METHODS[($offset + $remainder + $index) % 3];
                if ($scheduled === $limit) {
                    break;
                }

                $scheduled += $this->{$schedule}($now, $limit - $scheduled);
            }

            $nextOffset = ($offset + $remainder) % 3;
            $cursorUpdate = $this->pdo->prepare(
                'UPDATE website_check_schedule_cursor SET next_kind = :next_kind WHERE id = 1'
            );
            $cursorUpdate->execute(['next_kind' => $nextOffset]);

            return $scheduled;
        });
    }

    public function enqueueManual(int $websiteId, DateTimeImmutable $now): int
    {
        if ($websiteId <= 0) {
            throw new InvalidArgumentException('Website does not exist.');
        }

        return $this->transaction('website_check_manual', function () use ($websiteId, $now): int {
            $previousLockTimeout = (string) $this->pdo
                ->query("SELECT current_setting('lock_timeout')")
                ->fetchColumn();
            $this->setLocalLockTimeout('2s');

            $website = $this->pdo->prepare(
                'SELECT id, domain_check_enabled
                 FROM websites
                 WHERE id = :website_id AND is_active = TRUE
                 FOR UPDATE'
            );
            $website->execute(['website_id' => $websiteId]);
            $row = $website->fetch();
            if (!is_array($row)) {
                $this->setLocalLockTimeout($previousLockTimeout);

                return 0;
            }

            $count = 0;
            $endpoints = $this->pdo->prepare(
                'SELECT id FROM website_endpoints
                 WHERE website_id = :website_id
                 ORDER BY id
                 FOR UPDATE'
            );
            $endpoints->execute(['website_id' => $websiteId]);
            foreach ($endpoints->fetchAll() as $endpoint) {
                $count += $this->insertManualHttp((int) $endpoint['id'], $websiteId, $now);
            }

            $tlsTargets = $this->pdo->prepare(
                'SELECT targets.id
                 FROM website_tls_targets AS targets
                 JOIN website_endpoints AS endpoints ON endpoints.id = targets.endpoint_id
                 WHERE targets.website_id = :website_id
                   AND endpoints.tls_expiry_enabled = TRUE
                 ORDER BY targets.id
                 FOR UPDATE OF targets'
            );
            $tlsTargets->execute(['website_id' => $websiteId]);
            foreach ($tlsTargets->fetchAll() as $target) {
                $count += $this->insertManualTls((int) $target['id'], $websiteId, $now);
            }

            if ($this->databaseBool($row['domain_check_enabled'])) {
                $count += $this->insertManualDomain($websiteId, $now);
            }

            $this->setLocalLockTimeout($previousLockTimeout);

            return $count;
        });
    }

    /** @return list<array<string, mixed>> */
    public function claim(string $leaseOwner, DateTimeImmutable $now, int $limit): array
    {
        if ($leaseOwner === '' || strlen($leaseOwner) > 80) {
            throw new InvalidArgumentException('Website check lease owner is invalid.');
        }
        $this->assertLimit($limit);

        return $this->transaction('website_check_claim', function () use ($leaseOwner, $now, $limit): array {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                WITH claimable AS (
                    SELECT id
                    FROM website_check_jobs
                    WHERE available_at <= CAST(:now AS timestamptz)
                      AND attempts < :max_attempts
                      AND (
                          state = 'pending'
                          OR (state = 'leased' AND lease_until < CAST(:now AS timestamptz))
                      )
                    ORDER BY manual DESC, priority DESC, available_at, id
                    FOR UPDATE SKIP LOCKED
                    LIMIT :limit
                )
                UPDATE website_check_jobs AS jobs
                SET state = 'leased',
                    lease_owner = :lease_owner,
                    lease_until = CAST(:lease_until AS timestamptz),
                    attempts = jobs.attempts + 1
                FROM claimable
                WHERE jobs.id = claimable.id
                RETURNING jobs.*
                SQL
            );
            $statement->bindValue('now', $this->timestamp($now));
            $statement->bindValue('max_attempts', self::MAX_ATTEMPTS, PDO::PARAM_INT);
            $statement->bindValue('lease_owner', $leaseOwner);
            $statement->bindValue(
                'lease_until',
                $this->timestamp($now->modify(sprintf('+%d seconds', self::LEASE_SECONDS)))
            );
            $statement->bindValue('limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            $jobs = $statement->fetchAll();
            foreach ($jobs as &$job) {
                $job['id'] = (int) $job['id'];
                $job['website_id'] = (int) $job['website_id'];
                $job['endpoint_id'] = $job['endpoint_id'] === null ? null : (int) $job['endpoint_id'];
                $job['tls_target_id'] = $job['tls_target_id'] === null ? null : (int) $job['tls_target_id'];
                $job['manual'] = $this->databaseBool($job['manual']);
                $job['priority'] = (int) $job['priority'];
                $job['attempts'] = (int) $job['attempts'];
            }
            unset($job);

            return $jobs;
        });
    }

    public function complete(int $jobId, string $leaseOwner): void
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM website_check_jobs
             WHERE id = :id
               AND state = 'leased'
               AND lease_owner = :lease_owner
               AND lease_until > clock_timestamp()"
        );
        $statement->execute(['id' => $jobId, 'lease_owner' => $leaseOwner]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Website check lease is not owned or has expired.');
        }
    }

    public function release(
        int $jobId,
        string $leaseOwner,
        DateTimeImmutable $availableAt,
        string $safeError,
    ): void {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $safeError)) {
            throw new InvalidArgumentException('Website check error category is invalid.');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE website_check_jobs
            SET state = 'pending',
                lease_owner = NULL,
                lease_until = NULL,
                available_at = CAST(:available_at AS timestamptz),
                safe_error_kind = :safe_error_kind
            WHERE id = :id
              AND state = 'leased'
              AND lease_owner = :lease_owner
              AND lease_until > clock_timestamp()
            SQL
        );
        $statement->execute([
            'id' => $jobId,
            'lease_owner' => $leaseOwner,
            'available_at' => $this->timestamp($availableAt),
            'safe_error_kind' => $safeError,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Website check lease is not owned or has expired.');
        }
    }

    private function scheduleHttp(DateTimeImmutable $now, int $limit): int
    {
        $due = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                endpoints.id AS endpoint_id,
                endpoints.website_id,
                endpoints.next_http_check_at AS scheduled_for,
                COALESCE(endpoints.interval_seconds, websites.default_interval_seconds) AS interval_seconds
            FROM website_endpoints AS endpoints
            JOIN websites ON websites.id = endpoints.website_id
            WHERE websites.is_active = TRUE
              AND endpoints.next_http_check_at <= CAST(:now AS timestamptz)
            ORDER BY endpoints.next_http_check_at, endpoints.id
            FOR UPDATE OF endpoints, websites SKIP LOCKED
            LIMIT :limit
            SQL
        );
        $due->bindValue('now', $this->timestamp($now));
        $due->bindValue('limit', $limit, PDO::PARAM_INT);
        $due->execute();

        $count = 0;
        foreach ($due->fetchAll() as $row) {
            $count += $this->insertScheduledHttp(
                (int) $row['endpoint_id'],
                (int) $row['website_id'],
                (string) $row['scheduled_for'],
                $now
            );
            $advance = $this->pdo->prepare(
                "UPDATE website_endpoints
                 SET next_http_check_at = CAST(:now AS timestamptz)
                     + CAST(:interval_seconds AS integer) * INTERVAL '1 second'
                 WHERE id = :id"
            );
            $advance->execute([
                'id' => $row['endpoint_id'],
                'now' => $this->timestamp($now),
                'interval_seconds' => (int) $row['interval_seconds'],
            ]);
        }

        return $count;
    }

    private function scheduleTls(DateTimeImmutable $now, int $limit): int
    {
        $due = $this->pdo->prepare(
            <<<'SQL'
            SELECT targets.id AS tls_target_id, targets.website_id, targets.next_check_at AS scheduled_for
            FROM website_tls_targets AS targets
            JOIN websites ON websites.id = targets.website_id
            JOIN website_endpoints AS endpoints ON endpoints.id = targets.endpoint_id
            WHERE websites.is_active = TRUE
              AND endpoints.tls_expiry_enabled = TRUE
              AND targets.next_check_at <= CAST(:now AS timestamptz)
            ORDER BY targets.next_check_at, targets.id
            FOR UPDATE OF targets, websites, endpoints SKIP LOCKED
            LIMIT :limit
            SQL
        );
        $due->bindValue('now', $this->timestamp($now));
        $due->bindValue('limit', $limit, PDO::PARAM_INT);
        $due->execute();

        $count = 0;
        foreach ($due->fetchAll() as $row) {
            $count += $this->insertScheduledTls(
                (int) $row['tls_target_id'],
                (int) $row['website_id'],
                (string) $row['scheduled_for'],
                $now
            );
            $this->pdo->prepare(
                "UPDATE website_tls_targets
                 SET next_check_at = CAST(:now AS timestamptz) + INTERVAL '1 day'
                 WHERE id = :id"
            )->execute([
                'id' => $row['tls_target_id'],
                'now' => $this->timestamp($now),
            ]);
        }

        return $count;
    }

    private function scheduleDomain(DateTimeImmutable $now, int $limit): int
    {
        $due = $this->pdo->prepare(
            <<<'SQL'
            SELECT id AS website_id, domain_next_check_at AS scheduled_for
            FROM websites
            WHERE is_active = TRUE
              AND domain_check_enabled = TRUE
              AND domain_next_check_at <= CAST(:now AS timestamptz)
            ORDER BY domain_next_check_at, id
            FOR UPDATE SKIP LOCKED
            LIMIT :limit
            SQL
        );
        $due->bindValue('now', $this->timestamp($now));
        $due->bindValue('limit', $limit, PDO::PARAM_INT);
        $due->execute();

        $count = 0;
        foreach ($due->fetchAll() as $row) {
            $count += $this->insertScheduledDomain(
                (int) $row['website_id'],
                (string) $row['scheduled_for'],
                $now
            );
            $this->pdo->prepare(
                "UPDATE websites
                 SET domain_next_check_at = CAST(:now AS timestamptz) + INTERVAL '1 day'
                 WHERE id = :id"
            )->execute([
                'id' => $row['website_id'],
                'now' => $this->timestamp($now),
            ]);
        }

        return $count;
    }

    private function insertScheduledHttp(
        int $endpointId,
        int $websiteId,
        string $scheduledFor,
        DateTimeImmutable $now,
    ): int {
        return $this->insertJob(
            $websiteId,
            $endpointId,
            null,
            'http',
            false,
            0,
            $scheduledFor,
            $now
        );
    }

    private function insertScheduledTls(
        int $tlsTargetId,
        int $websiteId,
        string $scheduledFor,
        DateTimeImmutable $now,
    ): int {
        return $this->insertJob(
            $websiteId,
            null,
            $tlsTargetId,
            'tls',
            false,
            0,
            $scheduledFor,
            $now
        );
    }

    private function insertScheduledDomain(int $websiteId, string $scheduledFor, DateTimeImmutable $now): int
    {
        return $this->insertJob($websiteId, null, null, 'domain', false, 0, $scheduledFor, $now);
    }

    private function insertManualHttp(int $endpointId, int $websiteId, DateTimeImmutable $now): int
    {
        return $this->insertManualJob($websiteId, $endpointId, null, 'http', $now);
    }

    private function insertManualTls(int $tlsTargetId, int $websiteId, DateTimeImmutable $now): int
    {
        return $this->insertManualJob($websiteId, null, $tlsTargetId, 'tls', $now);
    }

    private function insertManualDomain(int $websiteId, DateTimeImmutable $now): int
    {
        return $this->insertManualJob($websiteId, null, null, 'domain', $now);
    }

    private function insertManualJob(
        int $websiteId,
        ?int $endpointId,
        ?int $tlsTargetId,
        string $kind,
        DateTimeImmutable $now,
    ): int {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO website_check_jobs (
                website_id, endpoint_id, tls_target_id, kind, manual, priority,
                scheduled_for, available_at
            )
            SELECT
                :website_id, :endpoint_id, :tls_target_id, CAST(:kind AS varchar(10)), TRUE, :priority,
                CAST(:scheduled_for AS timestamptz), CAST(:available_at AS timestamptz)
            WHERE NOT EXISTS (
                SELECT 1
                FROM website_check_jobs AS existing
                WHERE existing.website_id = :existing_website_id
                  AND existing.kind = CAST(:existing_kind AS varchar(10))
                  AND existing.manual = TRUE
                  AND existing.state IN ('pending', 'leased')
                  AND existing.endpoint_id IS NOT DISTINCT FROM CAST(:existing_endpoint_id AS bigint)
                  AND existing.tls_target_id IS NOT DISTINCT FROM CAST(:existing_tls_target_id AS bigint)
            )
            SQL
        );
        $statement->execute([
            'website_id' => $websiteId,
            'existing_website_id' => $websiteId,
            'endpoint_id' => $endpointId,
            'existing_endpoint_id' => $endpointId,
            'tls_target_id' => $tlsTargetId,
            'existing_tls_target_id' => $tlsTargetId,
            'kind' => $kind,
            'existing_kind' => $kind,
            'priority' => self::MANUAL_PRIORITY,
            'scheduled_for' => $this->timestamp($now),
            'available_at' => $this->timestamp($now),
        ]);

        return $statement->rowCount();
    }

    private function insertJob(
        int $websiteId,
        ?int $endpointId,
        ?int $tlsTargetId,
        string $kind,
        bool $manual,
        int $priority,
        string $scheduledFor,
        DateTimeImmutable $availableAt,
    ): int {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO website_check_jobs (
                website_id, endpoint_id, tls_target_id, kind, manual, priority,
                scheduled_for, available_at
            ) VALUES (
                :website_id, :endpoint_id, :tls_target_id, :kind, :manual, :priority,
                CAST(:scheduled_for AS timestamptz), CAST(:available_at AS timestamptz)
            )
            ON CONFLICT DO NOTHING
            SQL
        );
        $statement->execute([
            'website_id' => $websiteId,
            'endpoint_id' => $endpointId,
            'tls_target_id' => $tlsTargetId,
            'kind' => $kind,
            'manual' => $manual ? 1 : 0,
            'priority' => $priority,
            'scheduled_for' => $scheduledFor,
            'available_at' => $this->timestamp($availableAt),
        ]);

        return $statement->rowCount();
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Website check queue limit must be between 1 and 100.');
        }
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format(DateTimeInterface::ATOM);
    }

    private function databaseBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function setLocalLockTimeout(string $value): void
    {
        $statement = $this->pdo->prepare("SELECT set_config('lock_timeout', :value, TRUE)");
        $statement->execute(['value' => $value]);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function transaction(string $savepoint, callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }
}
