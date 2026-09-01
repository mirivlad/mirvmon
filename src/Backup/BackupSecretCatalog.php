<?php

declare(strict_types=1);

namespace App\Backup;

use App\Security\SecretCipher;
use PDO;
use RuntimeException;
use Throwable;

final class BackupSecretCatalog
{
    private const VERSION = 1;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretCipher $cipher
    ) {
    }

    /**
     * Export plaintext secret material for placement only inside the encrypted backup payload.
     *
     * @return array{
     *   version:int,
     *   notification:array{
     *     smtp_password:?string,
     *     telegram_bot_token:?string,
     *     telegram_proxy_password:?string
     *   },
     *   website_endpoints:list<array{id:int,auth:?string,headers:?string}>
     * }
     */
    public function export(): array
    {
        $notification = $this->pdo->query(
            'SELECT smtp_password_encrypted, telegram_bot_token_encrypted,
                    telegram_proxy_password_encrypted
             FROM notification_settings WHERE id = 1'
        )?->fetch();
        if (!is_array($notification)) {
            throw new RuntimeException('Notification settings row is missing.');
        }

        $endpoints = [];
        $statement = $this->pdo->query(
            'SELECT id, auth_encrypted, headers_encrypted
             FROM website_endpoints
             ORDER BY id'
        );
        foreach ($statement?->fetchAll() ?? [] as $row) {
            $endpoints[] = [
                'id' => (int) $row['id'],
                'auth' => $this->decryptNullable($row['auth_encrypted']),
                'headers' => $this->decryptNullable($row['headers_encrypted']),
            ];
        }

        return [
            'version' => self::VERSION,
            'notification' => [
                'smtp_password' => $this->decryptNullable($notification['smtp_password_encrypted']),
                'telegram_bot_token' => $this->decryptNullable($notification['telegram_bot_token_encrypted']),
                'telegram_proxy_password' => $this->decryptNullable($notification['telegram_proxy_password_encrypted']),
            ],
            'website_endpoints' => $endpoints,
        ];
    }

    /**
     * Validates only payload structure and bounds. It intentionally does not compare endpoint IDs
     * with the currently running database, so it is safe to call during preflight on a clean B.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *   notification:array{
     *     smtp_password:?string,
     *     telegram_bot_token:?string,
     *     telegram_proxy_password:?string
     *   },
     *   website_endpoints:list<array{id:int,auth:?string,headers:?string}>
     * }
     */
    public function validate(array $payload): array
    {
        if (($payload['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Unsupported backup secret payload version.');
        }
        $notification = $payload['notification'] ?? null;
        $endpoints = $payload['website_endpoints'] ?? null;
        if (!is_array($notification) || !is_array($endpoints) || !array_is_list($endpoints)) {
            throw new RuntimeException('Invalid backup secret payload.');
        }

        $notificationKeys = [
            'smtp_password',
            'telegram_bot_token',
            'telegram_proxy_password',
        ];
        if (array_keys($notification) !== $notificationKeys) {
            throw new RuntimeException('Invalid notification secret payload.');
        }
        $normalizedNotification = [];
        foreach ($notificationKeys as $key) {
            $normalizedNotification[$key] = $this->nullableSecret($notification[$key], $key);
        }

        $seenIds = [];
        $normalizedEndpoints = [];
        $previousId = 0;
        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint) || array_keys($endpoint) !== ['id', 'auth', 'headers']) {
                throw new RuntimeException('Invalid website endpoint secret payload.');
            }
            $id = $endpoint['id'];
            if (!is_int($id) || $id < 1 || isset($seenIds[$id]) || $id <= $previousId) {
                throw new RuntimeException('Invalid website endpoint secret identifier.');
            }
            $seenIds[$id] = true;
            $previousId = $id;
            $normalizedEndpoints[] = [
                'id' => $id,
                'auth' => $this->nullableSecret($endpoint['auth'], 'website auth'),
                'headers' => $this->nullableSecret($endpoint['headers'], 'website headers'),
            ];
        }

        return [
            'notification' => $normalizedNotification,
            'website_endpoints' => $normalizedEndpoints,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function apply(array $payload): void
    {
        $normalized = $this->validate($payload);
        $this->assertRestoredEndpointSet($normalized['website_endpoints']);

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT backup_secret_catalog');
        }

        try {
            $notification = $normalized['notification'];
            $statement = $this->pdo->prepare(
                'UPDATE notification_settings SET
                    smtp_password_encrypted = :smtp_password,
                    telegram_bot_token_encrypted = :telegram_bot_token,
                    telegram_proxy_password_encrypted = :telegram_proxy_password,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = 1'
            );
            $statement->execute([
                'smtp_password' => $this->encryptNullable($notification['smtp_password']),
                'telegram_bot_token' => $this->encryptNullable($notification['telegram_bot_token']),
                'telegram_proxy_password' => $this->encryptNullable($notification['telegram_proxy_password']),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Notification settings row is missing during restore.');
            }

            $update = $this->pdo->prepare(
                'UPDATE website_endpoints SET
                    auth_encrypted = :auth,
                    headers_encrypted = :headers
                 WHERE id = :id'
            );
            foreach ($normalized['website_endpoints'] as $endpoint) {
                $update->execute([
                    'id' => $endpoint['id'],
                    'auth' => $this->encryptNullable($endpoint['auth']),
                    'headers' => $this->encryptNullable($endpoint['headers']),
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Website endpoint disappeared during secret restore.');
                }
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT backup_secret_catalog');
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT backup_secret_catalog');
                $this->pdo->exec('RELEASE SAVEPOINT backup_secret_catalog');
            }
            throw $exception;
        }
    }

    /** @param list<array{id:int,auth:?string,headers:?string}> $endpoints */
    private function assertRestoredEndpointSet(array $endpoints): void
    {
        $expectedIds = array_map(
            'intval',
            $this->pdo->query('SELECT id FROM website_endpoints ORDER BY id')?->fetchAll(PDO::FETCH_COLUMN) ?? []
        );
        $actualIds = array_map(static fn (array $row): int => $row['id'], $endpoints);
        if ($actualIds !== $expectedIds) {
            throw new RuntimeException('Backup secret payload does not match restored website endpoints.');
        }
    }

    private function nullableSecret(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || strlen($value) > 65536) {
            throw new RuntimeException('Invalid backup secret value: ' . $label);
        }
        return $value;
    }

    private function decryptNullable(mixed $value): ?string
    {
        $bytes = $this->databaseBytes($value);
        return $bytes === null ? null : $this->cipher->decrypt($bytes);
    }

    private function encryptNullable(?string $value): ?string
    {
        return $value === null ? null : $this->cipher->encrypt($value);
    }

    private function databaseBytes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if ($contents === false) {
                throw new RuntimeException('Cannot read encrypted database value.');
            }
            return $contents;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Invalid encrypted database value.');
        }
        if (str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));
            if ($decoded === false) {
                throw new RuntimeException('Invalid bytea database value.');
            }
            return $decoded;
        }
        return $value;
    }
}
