<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;

final class AuditLogger
{
    private const REDACTED = '[redacted]';
    private const TRUNCATED = '[truncated]';
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 100;
    private const MAX_STRING = 2000;

    public function __construct(private readonly AuditLogRepository $repository)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function record(
        string $action,
        string $objectType,
        int|string|null $objectId,
        ?string $objectLabel,
        string $description,
        array $metadata = []
    ): int {
        $userId = filter_var(
            $_SESSION['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $username = is_string($_SESSION['username'] ?? null)
            ? trim((string) $_SESSION['username'])
            : '';
        $role = is_string($_SESSION['role'] ?? null)
            ? trim((string) $_SESSION['role'])
            : null;

        return $this->repository->append(
            $userId === false ? null : (int) $userId,
            $username === '' ? 'system' : substr($username, 0, 80),
            $role === '' ? null : ($role === null ? null : substr($role, 0, 20)),
            $this->identifier($action, 80, 'unknown'),
            $this->identifier($objectType, 40, 'unknown'),
            $objectId === null ? null : substr((string) $objectId, 0, 100),
            $objectLabel === null || trim($objectLabel) === ''
                ? null
                : substr(trim($objectLabel), 0, 255),
            substr(trim($description), 0, 4000),
            self::sanitizeMetadata($metadata)
        );
    }

    /**
     * Recursively removes credential-like values before they can enter the audit log.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function sanitizeMetadata(array $metadata): array
    {
        $sanitized = self::sanitizeValue($metadata, 0);
        return is_array($sanitized) ? $sanitized : [];
    }

    private function identifier(string $value, int $maximum, string $fallback): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9_.-]+$/', $value) !== 1) {
            return $fallback;
        }
        return substr($value, 0, $maximum);
    }

    private static function secretKey(string $key): bool
    {
        return preg_match(
            '/(?:password|passphrase|token|secret|credential|authorization|cookie|api[_-]?key|app[_-]?key|smtp[_-]?password|bot[_-]?token)/i',
            $key
        ) === 1;
    }

    private static function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::TRUNCATED;
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (is_string($value)) {
            return substr($value, 0, self::MAX_STRING);
        }
        if (!is_array($value)) {
            return self::TRUNCATED;
        }

        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count++ >= self::MAX_ITEMS) {
                $result['__truncated__'] = self::TRUNCATED;
                break;
            }
            $normalizedKey = is_int($key) ? $key : substr((string) $key, 0, 120);
            if (is_string($normalizedKey) && self::secretKey($normalizedKey)) {
                $result[$normalizedKey] = self::REDACTED;
                continue;
            }
            $result[$normalizedKey] = self::sanitizeValue($item, $depth + 1);
        }
        return $result;
    }
}
