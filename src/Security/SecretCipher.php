<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class SecretCipher
{
    private const PREFIX = 'v1:';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Secret encryption key must contain exactly 32 bytes.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encrypted): string
    {
        if (!str_starts_with($encrypted, self::PREFIX)) {
            throw new RuntimeException('Unsupported encrypted secret version.');
        }

        $decoded = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);
        if (
            $decoded === false
            || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        ) {
            throw new RuntimeException('Invalid encrypted secret.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new RuntimeException('Encrypted secret authentication failed.');
        }

        return $plaintext;
    }
}
