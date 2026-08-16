<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    public function testSecretLikeMetadataIsRecursivelyRedacted(): void
    {
        $sanitized = AuditLogger::sanitizeMetadata([
            'changed_fields' => ['name', 'smtp_password'],
            'password' => 'plain-secret',
            'nested' => [
                'api_key' => 'key-value',
                'safe' => 'visible',
                'token_generation' => 7,
            ],
            'authorization' => 'Bearer abc',
            'count' => 3,
        ]);

        self::assertSame('[redacted]', $sanitized['password']);
        self::assertSame('[redacted]', $sanitized['nested']['api_key']);
        self::assertSame('[redacted]', $sanitized['nested']['token_generation']);
        self::assertSame('[redacted]', $sanitized['authorization']);
        self::assertSame('visible', $sanitized['nested']['safe']);
        self::assertSame(3, $sanitized['count']);
        self::assertSame(['name', 'smtp_password'], $sanitized['changed_fields']);
        self::assertStringNotContainsString(
            'plain-secret',
            (string) json_encode($sanitized, JSON_UNESCAPED_SLASHES)
        );
    }

    public function testOversizedAndUnsupportedMetadataIsBounded(): void
    {
        $sanitized = AuditLogger::sanitizeMetadata([
            'long' => str_repeat('x', 2500),
            'object' => new \stdClass(),
            'infinite' => INF,
        ]);

        self::assertSame(2000, strlen((string) $sanitized['long']));
        self::assertSame('[truncated]', $sanitized['object']);
        self::assertNull($sanitized['infinite']);
    }
}
