<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\SecretCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretCipherTest extends TestCase
{
    public function testSecretIsAuthenticatedAndVersioned(): void
    {
        $cipher = new SecretCipher(str_repeat('k', 32));

        $encrypted = $cipher->encrypt('telegram-secret');

        self::assertStringStartsWith('v1:', $encrypted);
        self::assertStringNotContainsString('telegram-secret', $encrypted);
        self::assertSame('telegram-secret', $cipher->decrypt($encrypted));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $cipher = new SecretCipher(str_repeat('k', 32));
        $encrypted = $cipher->encrypt('smtp-secret');
        $encrypted[-1] = $encrypted[-1] === 'A' ? 'B' : 'A';

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($encrypted);
    }

    public function testKeyMustContainExactlyThirtyTwoBytes(): void
    {
        $this->expectException(RuntimeException::class);
        new SecretCipher('too-short');
    }
}
