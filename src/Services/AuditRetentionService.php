<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppSettingsRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AuditRetentionService
{
    public const SETTING_KEY = 'audit_retention_days';
    public const KEEP_FOREVER = 0;
    public const MINIMUM_DAYS = 30;
    public const MAXIMUM_DAYS = 3650;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AppSettingsRepository $settings
    ) {
    }

    public function retentionDays(): int
    {
        $value = $this->settings->get(self::SETTING_KEY, self::KEEP_FOREVER);
        if (!is_int($value) && !is_string($value)) {
            return self::KEEP_FOREVER;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return self::KEEP_FOREVER;
        }

        $days = (int) $value;
        return $this->isValid($days) ? $days : self::KEEP_FOREVER;
    }

    public function saveRetentionDays(int $days): void
    {
        if (!$this->isValid($days)) {
            throw new InvalidArgumentException('Invalid audit retention period.');
        }
        $this->settings->set(self::SETTING_KEY, $days);
    }

    public function pruneExpired(?DateTimeImmutable $now = null): int
    {
        $days = $this->retentionDays();
        if ($days === self::KEEP_FOREVER) {
            return 0;
        }

        $now ??= new DateTimeImmutable();
        $cutoff = $now->modify('-' . $days . ' days');
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT mirvmon_prune_audit_log(CAST(:cutoff AS timestamptz))'
            );
            $statement->execute(['cutoff' => $cutoff->format(DATE_ATOM)]);
            $deleted = (int) $statement->fetchColumn();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $deleted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function isValid(int $days): bool
    {
        return $days === self::KEEP_FOREVER
            || ($days >= self::MINIMUM_DAYS && $days <= self::MAXIMUM_DAYS);
    }
}
