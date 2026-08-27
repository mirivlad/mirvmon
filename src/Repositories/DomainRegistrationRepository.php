<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Websites\DomainRegistrationResult;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDO;
use Throwable;

final class DomainRegistrationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(int $websiteId, DomainRegistrationResult $result): void
    {
        if ($websiteId <= 0) {
            throw new InvalidArgumentException('Website does not exist.');
        }
        $this->transaction(function () use ($websiteId, $result): void {
            $website = $this->pdo->prepare(
                'SELECT domain_check_enabled FROM websites WHERE id = :website_id FOR UPDATE'
            );
            $website->execute(['website_id' => $websiteId]);
            if (!is_array($website->fetch())) {
                throw new InvalidArgumentException('Website does not exist.');
            }
            $state = $this->pdo->prepare(
                'SELECT retry_count FROM website_domain_state WHERE website_id = :website_id FOR UPDATE'
            );
            $state->execute(['website_id' => $websiteId]);
            $previous = $state->fetch();
            $retryCount = is_array($previous) ? (int) $previous['retry_count'] : 0;
            $nextCheckAt = $this->nextCheckAt($result, $retryCount);
            $newRetryCount = in_array($result->status, ['supported', 'unsupported'], true)
                ? 0
                : min($retryCount + 1, 16);
            $upsert = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO website_domain_state (
                    website_id, status, expires_at, registrar, source, error_kind, checked_at, retry_count
                ) VALUES (
                    :website_id, :status, :expires_at, :registrar, :source, :error_kind, :checked_at, :retry_count
                )
                ON CONFLICT (website_id) DO UPDATE SET
                    status = EXCLUDED.status,
                    expires_at = EXCLUDED.expires_at,
                    registrar = EXCLUDED.registrar,
                    source = EXCLUDED.source,
                    error_kind = EXCLUDED.error_kind,
                    checked_at = EXCLUDED.checked_at,
                    retry_count = EXCLUDED.retry_count
                SQL
            );
            $upsert->execute([
                'website_id' => $websiteId,
                'status' => $this->stateStatus($result),
                'expires_at' => $result->expiresAt?->format(DateTimeInterface::ATOM),
                'registrar' => $result->registrar,
                'source' => $result->source,
                'error_kind' => $result->errorKind,
                'checked_at' => $result->checkedAt->format(DateTimeInterface::ATOM),
                'retry_count' => $newRetryCount,
            ]);
            $schedule = $this->pdo->prepare(
                'UPDATE websites SET domain_next_check_at = :next_check_at WHERE id = :website_id'
            );
            $schedule->execute([
                'website_id' => $websiteId,
                'next_check_at' => $nextCheckAt->format(DateTimeInterface::ATOM),
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function dueWebsites(DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Domain due website limit is invalid.');
        }
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT id AS website_id, registration_domain, domain_next_check_at
            FROM websites
            WHERE is_active = TRUE
              AND domain_check_enabled = TRUE
              AND domain_next_check_at <= :now
            ORDER BY domain_next_check_at, id
            LIMIT :limit
            SQL
        );
        $statement->bindValue('now', $now->format(DateTimeInterface::ATOM));
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function stateStatus(DomainRegistrationResult $result): string
    {
        return match ($result->status) {
            'supported' => 'healthy',
            'unsupported' => 'unsupported',
            default => 'unknown',
        };
    }

    private function nextCheckAt(DomainRegistrationResult $result, int $retryCount): DateTimeImmutable
    {
        if (in_array($result->status, ['supported', 'unsupported'], true)) {
            return $result->checkedAt->modify('+24 hours');
        }
        if ($result->errorKind === 'rate_limited' && $result->retryAfterSeconds !== null) {
            return $result->checkedAt->modify('+' . min(86400, max(1, $result->retryAfterSeconds)) . ' seconds');
        }

        return $result->checkedAt->modify('+' . min(21600, 60 * (2 ** min(8, max(0, $retryCount)))) . ' seconds');
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
