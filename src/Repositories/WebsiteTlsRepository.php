<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Websites\TlsInspectionResult;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class WebsiteTlsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<array{hostname:string,port:int,configured:bool}> $targets */
    public function syncTargets(int $endpointId, array $targets): void
    {
        if ($endpointId <= 0) {
            throw new InvalidArgumentException('Website endpoint does not exist.');
        }
        $normalized = [];
        foreach ($targets as $target) {
            $hostname = strtolower(rtrim($target['hostname'], '.'));
            if ($hostname === '' || $target['port'] < 1 || $target['port'] > 65535) {
                throw new InvalidArgumentException('TLS target is invalid.');
            }
            $key = $hostname . ':' . $target['port'];
            $normalized[$key] = [
                'hostname' => $hostname,
                'port' => $target['port'],
                'configured' => ($normalized[$key]['configured'] ?? false) || $target['configured'],
            ];
        }

        $this->transaction(function () use ($endpointId, $normalized): void {
            $endpoint = $this->pdo->prepare(
                'SELECT website_id, allow_self_signed FROM website_endpoints WHERE id = :endpoint_id FOR UPDATE'
            );
            $endpoint->execute(['endpoint_id' => $endpointId]);
            $row = $endpoint->fetch();
            if (!is_array($row)) {
                throw new InvalidArgumentException('Website endpoint does not exist.');
            }
            $websiteId = (int) $row['website_id'];
            $allowSelfSigned = $this->databaseBool($row['allow_self_signed']);
            $existing = $this->pdo->prepare(
                'SELECT id, hostname, port FROM website_tls_targets WHERE endpoint_id = :endpoint_id FOR UPDATE'
            );
            $existing->execute(['endpoint_id' => $endpointId]);
            $existingRows = $existing->fetchAll();
            $keep = [];
            foreach ($normalized as $target) {
                $source = $target['configured'] ? 'configured' : 'redirect';
                $upsert = $this->pdo->prepare(
                    <<<'SQL'
                    INSERT INTO website_tls_targets (
                        website_id, endpoint_id, hostname, port, source, allow_self_signed
                    ) VALUES (
                        :website_id, :endpoint_id, :hostname, :port, :source, :allow_self_signed
                    )
                    ON CONFLICT (endpoint_id, hostname, port) DO UPDATE
                    SET source = EXCLUDED.source,
                        allow_self_signed = EXCLUDED.allow_self_signed
                    RETURNING id
                    SQL
                );
                $upsert->execute([
                    'website_id' => $websiteId,
                    'endpoint_id' => $endpointId,
                    'hostname' => $target['hostname'],
                    'port' => $target['port'],
                    'source' => $source,
                    'allow_self_signed' => $target['configured'] && $allowSelfSigned ? 1 : 0,
                ]);
                $keep[(int) $upsert->fetchColumn()] = true;
            }
            $delete = $this->pdo->prepare('DELETE FROM website_tls_targets WHERE id = :id');
            foreach ($existingRows as $target) {
                if (!isset($keep[(int) $target['id']])) {
                    $delete->execute(['id' => $target['id']]);
                }
            }
        });
    }

    /** @return array{changed:bool,previous_fingerprint:?string} */
    public function record(TlsInspectionResult $result): array
    {
        if ($result->targetId === null || $result->targetId <= 0) {
            throw new InvalidArgumentException('TLS inspection target is required.');
        }

        return $this->transaction(function () use ($result): array {
            $previous = $this->pdo->prepare(
                'SELECT fingerprint_sha256, retry_count FROM website_tls_state WHERE tls_target_id = :target_id FOR UPDATE'
            );
            $previous->execute(['target_id' => $result->targetId]);
            $state = $previous->fetch();
            $previousFingerprint = is_array($state) && is_string($state['fingerprint_sha256'])
                ? $state['fingerprint_sha256']
                : null;
            $retryCount = is_array($state) ? (int) $state['retry_count'] : 0;
            $changed = $result->valid
                && $previousFingerprint !== null
                && $result->fingerprintSha256 !== null
                && !hash_equals($previousFingerprint, $result->fingerprintSha256);
            $nextCheckAt = $result->valid
                ? $result->checkedAt->modify('+24 hours')
                : $result->checkedAt->modify('+' . $this->retrySeconds($retryCount) . ' seconds');
            $newRetryCount = $result->valid ? 0 : min($retryCount + 1, 16);
            $upsert = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO website_tls_state (
                    tls_target_id, status, subject, issuer, sans, fingerprint_sha256,
                    not_before, not_after, error_kind, checked_at, retry_count
                ) VALUES (
                    :target_id, :status, :subject, :issuer, CAST(:sans AS jsonb), :fingerprint,
                    :not_before, :not_after, :error_kind, :checked_at, :retry_count
                )
                ON CONFLICT (tls_target_id) DO UPDATE SET
                    status = EXCLUDED.status,
                    subject = EXCLUDED.subject,
                    issuer = EXCLUDED.issuer,
                    sans = EXCLUDED.sans,
                    fingerprint_sha256 = EXCLUDED.fingerprint_sha256,
                    not_before = EXCLUDED.not_before,
                    not_after = EXCLUDED.not_after,
                    error_kind = EXCLUDED.error_kind,
                    checked_at = EXCLUDED.checked_at,
                    retry_count = EXCLUDED.retry_count
                SQL
            );
            $upsert->execute([
                'target_id' => $result->targetId,
                'status' => $result->valid ? 'healthy' : 'error',
                'subject' => $result->subject,
                'issuer' => $result->issuer,
                'sans' => json_encode($result->sans, JSON_THROW_ON_ERROR),
                'fingerprint' => $result->fingerprintSha256,
                'not_before' => $result->notBefore?->format(DateTimeInterface::ATOM),
                'not_after' => $result->notAfter?->format(DateTimeInterface::ATOM),
                'error_kind' => $result->errorKind,
                'checked_at' => $result->checkedAt->format(DateTimeInterface::ATOM),
                'retry_count' => $newRetryCount,
            ]);
            $schedule = $this->pdo->prepare(
                'UPDATE website_tls_targets SET next_check_at = :next_check_at WHERE id = :target_id'
            );
            $schedule->execute([
                'target_id' => $result->targetId,
                'next_check_at' => $nextCheckAt->format(DateTimeInterface::ATOM),
            ]);
            if ($changed) {
                $event = $this->pdo->prepare(
                    <<<'SQL'
                    INSERT INTO website_certificate_events (
                        tls_target_id, previous_fingerprint_sha256, fingerprint_sha256, occurred_at, metadata
                    ) VALUES (
                        :target_id, :previous_fingerprint, :fingerprint, :occurred_at, '{}'::jsonb
                    )
                    SQL
                );
                $event->execute([
                    'target_id' => $result->targetId,
                    'previous_fingerprint' => $previousFingerprint,
                    'fingerprint' => $result->fingerprintSha256,
                    'occurred_at' => $result->checkedAt->format(DateTimeInterface::ATOM),
                ]);
            }

            return ['changed' => $changed, 'previous_fingerprint' => $previousFingerprint];
        });
    }

    /** @return list<array<string, mixed>> */
    public function dueTargets(DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('TLS due target limit is invalid.');
        }
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT targets.*, endpoints.allow_self_signed, endpoints.tls_expiry_enabled
            FROM website_tls_targets AS targets
            JOIN website_endpoints AS endpoints ON endpoints.id = targets.endpoint_id
            JOIN websites ON websites.id = targets.website_id
            WHERE targets.next_check_at <= :now
              AND endpoints.tls_expiry_enabled = TRUE
              AND websites.is_active = TRUE
            ORDER BY targets.next_check_at, targets.id
            LIMIT :limit
            SQL
        );
        $statement->bindValue('now', $now->format(DateTimeInterface::ATOM));
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function retrySeconds(int $retryCount): int
    {
        return min(21600, 60 * (2 ** min(8, max(0, $retryCount))));
    }

    private function databaseBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
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
