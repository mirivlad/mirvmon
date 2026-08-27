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

    public function testStructuredWebsiteCredentialsRemainOpaque(): void
    {
        $cipher = new SecretCipher(str_repeat('w', 32));
        $plaintext = json_encode([
            'type' => 'basic',
            'username' => 'monitor',
            'secret' => 'website-password',
        ], JSON_THROW_ON_ERROR);

        $encrypted = $cipher->encrypt($plaintext);

        self::assertStringNotContainsString('monitor', $encrypted);
        self::assertStringNotContainsString('website-password', $encrypted);
        self::assertSame($plaintext, $cipher->decrypt($encrypted));
    }

    public function testKeyMustContainExactlyThirtyTwoBytes(): void
    {
        $this->expectException(RuntimeException::class);
        new SecretCipher('too-short');
    }
}
