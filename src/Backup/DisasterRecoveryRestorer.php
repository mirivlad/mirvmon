<?php

declare(strict_types=1);

namespace App\Backup;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Security\SecretCipher;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class DisasterRecoveryRestorer
{
    /** @param array<string, string> $databaseEnvironment */
    public function __construct(
        private readonly array $databaseEnvironment,
        private readonly PostgresBackupTool $postgres,
        private readonly DrMaintenanceLock $maintenance,
        private readonly string $migrationDirectory,
        private readonly string $applicationKey,
        private readonly string $sessionDirectory
    ) {
        foreach (['DB_HOST', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
            if (trim($this->databaseEnvironment[$required] ?? '') === '') {
                throw new RuntimeException($required . ' is required for disaster recovery.');
            }
        }
        if (strlen($this->applicationKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY must decode to exactly 32 bytes for disaster recovery.');
        }
    }

    /**
     * Restores an already preflighted workspace while holding the global DR exclusive lock.
     * The preflight workspace is always removed because it contains plaintext secret material.
     *
     * @param array<string, mixed> $manifest
     * @return array{database:string,backup_id:string,migrations_applied:list<string>}
     */
    public function restore(string $workspace, array $manifest): array
    {
        $backupId = $manifest['backup_id'] ?? null;
        if (!is_string($backupId) || preg_match('/^[a-f0-9-]{36}$/', $backupId) !== 1) {
            throw new RuntimeException('Invalid backup ID for disaster recovery.');
        }
        $dumpPath = $workspace . DIRECTORY_SEPARATOR . 'database.pgdump';
        $secretPath = $workspace . DIRECTORY_SEPARATOR . 'secrets.json';
        if (!is_file($dumpPath) || !is_readable($dumpPath)
            || !is_file($secretPath) || !is_readable($secretPath)) {
            throw new RuntimeException('Restore workspace is incomplete.');
        }

        $secretPayload = $this->decodeSecrets($secretPath);
        $currentDatabase = $this->databaseEnvironment['DB_NAME'];
        $stagingDatabase = $this->derivedDatabaseName($currentDatabase, 'restore', $backupId);
        $previousDatabase = $this->derivedDatabaseName($currentDatabase, 'pre_restore', $backupId);
        $lease = $this->maintenance->beginExclusive([
            'operation' => 'restore',
            'backup_id' => $backupId,
        ]);
        $admin = null;
        $stagingExists = false;
        $cutoverComplete = false;

        try {
            $admin = $this->connectDatabase('postgres');
            $this->dropDatabaseIfExists($admin, $stagingDatabase);
            $this->dropDatabaseIfExists($admin, $previousDatabase);
            $this->createDatabase($admin, $stagingDatabase);
            $stagingExists = true;

            $staging = $this->connectDatabase($stagingDatabase);
            $staging->exec('CREATE EXTENSION IF NOT EXISTS timescaledb');
            $staging->query('SELECT timescaledb_pre_restore()')?->fetchColumn();
            try {
                $this->postgres->restore($dumpPath, $stagingDatabase);
            } catch (Throwable $exception) {
                try {
                    $staging->query('SELECT timescaledb_post_restore()')?->fetchColumn();
                } catch (Throwable) {
                    // The staging database is discarded below; preserve the original restore failure.
                }
                throw $exception;
            }
            $staging->query('SELECT timescaledb_post_restore()')?->fetchColumn();

            $this->assertRestoredMigrations($staging, $manifest);
            $migrationsApplied = (new Migrator($staging, $this->migrationDirectory))->migrate();

            $catalog = new BackupSecretCatalog($staging, new SecretCipher($this->applicationKey));
            $expectedSecrets = $catalog->validate($secretPayload);
            $catalog->apply($secretPayload);

            $normalizer = new RestoredDatabaseNormalizer($staging);
            $normalizer->normalize();
            $normalizer->assertIntegrity($manifest);
            $roundTripSecrets = $catalog->validate($catalog->export());
            if ($roundTripSecrets !== $expectedSecrets) {
                throw new RuntimeException('Restored application secrets failed re-encryption verification.');
            }
            $staging->exec('ANALYZE');
            $staging = null;

            $this->cutOver(
                $admin,
                $currentDatabase,
                $stagingDatabase,
                $previousDatabase,
                $manifest,
                $expectedSecrets
            );
            $stagingExists = false;
            $cutoverComplete = true;
            $this->invalidateSessions();

            return [
                'database' => $currentDatabase,
                'backup_id' => $backupId,
                'migrations_applied' => $migrationsApplied,
            ];
        } finally {
            if (!$cutoverComplete && $stagingExists && $admin instanceof PDO) {
                try {
                    $this->dropDatabaseIfExists($admin, $stagingDatabase);
                } catch (Throwable) {
                    // Keep the original restore failure. A later restore with the same backup ID
                    // force-drops this staging name before retrying.
                }
            }
            $this->removeTree($workspace);
            $lease->release();
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array{
     *   notification:array{smtp_password:?string,telegram_bot_token:?string,telegram_proxy_password:?string},
     *   website_endpoints:list<array{id:int,auth:?string,headers:?string}>
     * } $expectedSecrets
     */
    private function cutOver(
        PDO $admin,
        string $currentDatabase,
        string $stagingDatabase,
        string $previousDatabase,
        array $manifest,
        array $expectedSecrets
    ): void {
        $currentDisabled = false;
        $renamedCurrent = false;
        $renamedStaging = false;
        try {
            $this->setAllowsConnections($admin, $currentDatabase, false);
            $currentDisabled = true;
            $this->terminateDatabaseConnections($admin, $currentDatabase);
            $admin->exec(sprintf(
                'ALTER DATABASE %s RENAME TO %s',
                $this->identifier($currentDatabase),
                $this->identifier($previousDatabase)
            ));
            $renamedCurrent = true;
            $currentDisabled = false;

            $admin->exec(sprintf(
                'ALTER DATABASE %s RENAME TO %s',
                $this->identifier($stagingDatabase),
                $this->identifier($currentDatabase)
            ));
            $renamedStaging = true;
            $this->setAllowsConnections($admin, $currentDatabase, true);

            $restored = $this->connectDatabase($currentDatabase);
            $normalizer = new RestoredDatabaseNormalizer($restored);
            $normalizer->assertIntegrity($manifest);
            $catalog = new BackupSecretCatalog($restored, new SecretCipher($this->applicationKey));
            if ($catalog->validate($catalog->export()) !== $expectedSecrets) {
                throw new RuntimeException('Restored database failed post-cutover secret verification.');
            }
            $restored->query('SELECT 1')?->fetchColumn();
            $restored = null;

            $this->dropDatabaseIfExists($admin, $previousDatabase);
        } catch (Throwable $exception) {
            try {
                if ($renamedStaging) {
                    $this->setAllowsConnections($admin, $currentDatabase, false);
                    $this->terminateDatabaseConnections($admin, $currentDatabase);
                    $admin->exec(sprintf(
                        'ALTER DATABASE %s RENAME TO %s',
                        $this->identifier($currentDatabase),
                        $this->identifier($stagingDatabase)
                    ));
                }
                if ($renamedCurrent) {
                    $admin->exec(sprintf(
                        'ALTER DATABASE %s RENAME TO %s',
                        $this->identifier($previousDatabase),
                        $this->identifier($currentDatabase)
                    ));
                    $this->setAllowsConnections($admin, $currentDatabase, true);
                } elseif ($currentDisabled) {
                    $this->setAllowsConnections($admin, $currentDatabase, true);
                }
                if ($renamedStaging) {
                    $this->dropDatabaseIfExists($admin, $stagingDatabase);
                }
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    'Disaster recovery cutover failed and automatic database rollback also failed: '
                    . $rollbackException->getMessage(),
                    0,
                    $exception
                );
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertRestoredMigrations(PDO $pdo, array $manifest): void
    {
        $expected = $manifest['schema_migrations'] ?? null;
        if (!is_array($expected) || !array_is_list($expected)) {
            throw new RuntimeException('Backup migration manifest is missing during staging restore.');
        }
        $rows = $pdo->query(
            'SELECT version, checksum FROM schema_migrations ORDER BY version'
        )?->fetchAll() ?? [];
        $actual = array_map(
            static fn (array $row): array => [
                'version' => (string) $row['version'],
                'checksum' => (string) $row['checksum'],
            ],
            $rows
        );
        if ($actual !== $expected) {
            throw new RuntimeException('Restored schema_migrations does not match the backup manifest.');
        }
    }

    /** @return array<string, mixed> */
    private function decodeSecrets(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Cannot read restore secret payload.');
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid restore secret payload JSON.', 0, $exception);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid restore secret payload.');
        }
        return $payload;
    }

    private function createDatabase(PDO $admin, string $database): void
    {
        $admin->exec('CREATE DATABASE ' . $this->identifier($database) . ' TEMPLATE template0');
    }

    private function dropDatabaseIfExists(PDO $admin, string $database): void
    {
        $this->terminateDatabaseConnections($admin, $database);
        $admin->exec('DROP DATABASE IF EXISTS ' . $this->identifier($database) . ' WITH (FORCE)');
    }

    private function setAllowsConnections(PDO $admin, string $database, bool $allowed): void
    {
        $admin->exec(sprintf(
            'ALTER DATABASE %s WITH ALLOW_CONNECTIONS %s',
            $this->identifier($database),
            $allowed ? 'true' : 'false'
        ));
    }

    private function terminateDatabaseConnections(PDO $admin, string $database): void
    {
        $statement = $admin->prepare(
            'SELECT pg_terminate_backend(pid)
             FROM pg_stat_activity
             WHERE datname = :database
               AND pid <> pg_backend_pid()'
        );
        $statement->execute(['database' => $database]);
        $statement->fetchAll();
    }

    private function connectDatabase(string $database): PDO
    {
        $environment = $this->databaseEnvironment;
        $environment['DB_NAME'] = $database;
        return ConnectionFactory::connect($environment);
    }

    private function derivedDatabaseName(string $base, string $kind, string $backupId): string
    {
        $safeBase = preg_replace('/[^A-Za-z0-9_]/', '_', $base) ?: 'mirvmon';
        $token = substr(str_replace('-', '', $backupId), 0, 8);
        $suffix = '_' . $kind . '_' . $token;
        $maximumBase = 63 - strlen($suffix);
        if ($maximumBase < 1) {
            throw new RuntimeException('Cannot derive PostgreSQL disaster recovery database name.');
        }
        return substr($safeBase, 0, $maximumBase) . $suffix;
    }

    private function identifier(string $identifier): string
    {
        if ($identifier === '' || strlen($identifier) > 63 || str_contains($identifier, "\0")) {
            throw new RuntimeException('Invalid PostgreSQL database identifier.');
        }
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function invalidateSessions(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
            @session_write_close();
        }
        if (!is_dir($this->sessionDirectory)) {
            return;
        }
        foreach (scandir($this->sessionDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->sessionDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
