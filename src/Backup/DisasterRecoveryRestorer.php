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
        private readonly DrCutoverJournal $journal,
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
     * Workspace cleanup belongs to RestoreOperationStore so worker crashes remain recoverable.
     *
     * @param array<string, mixed> $manifest
     * @return array{database:string,backup_id:string,migrations_applied:list<string>}
     */
    public function restore(string $operationId, string $workspace, array $manifest): array
    {
        $this->assertOperationId($operationId);
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
            'operation_id' => $operationId,
            'backup_id' => $backupId,
        ]);
        $admin = null;
        $stagingExists = false;
        $journalStarted = false;
        $cutoverComplete = false;

        try {
            if ($this->journal->read() !== null) {
                throw new RuntimeException('An interrupted disaster-recovery cutover must be recovered first.');
            }

            $admin = $this->connectDatabase('postgres');
            if ($this->databaseExists($admin, $previousDatabase)) {
                throw new RuntimeException(
                    'A previous disaster-recovery database exists without a cutover journal; manual recovery is required.'
                );
            }
            $this->dropDatabaseIfExists($admin, $stagingDatabase);
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

            $result = [
                'database' => $currentDatabase,
                'backup_id' => $backupId,
                'migrations_applied' => $migrationsApplied,
            ];
            $this->journal->begin(
                $operationId,
                $backupId,
                $currentDatabase,
                $stagingDatabase,
                $previousDatabase,
                $result
            );
            $journalStarted = true;
            $this->cutOver($admin, $manifest, $expectedSecrets);
            $stagingExists = false;
            $cutoverComplete = true;
            $this->invalidateSessions();

            return $result;
        } finally {
            if (!$cutoverComplete
                && !$journalStarted
                && $stagingExists
                && $admin instanceof PDO) {
                try {
                    $this->dropDatabaseIfExists($admin, $stagingDatabase);
                } catch (Throwable) {
                    // Preserve the original failure; a later retry force-drops this staging name.
                }
            }
            $lease->release();
        }
    }

    /**
     * Resolves a journal left by a killed DR worker. Before verification the conservative action
     * is rollback to B; after verification the conservative action is completion of the cutover.
     *
     * @return array{outcome:'completed'|'rolled_back',operation_id:string,result:array<string,mixed>}|null
     */
    public function recoverInterruptedCutover(): ?array
    {
        $journal = $this->journal->read();
        if ($journal === null) {
            return null;
        }
        $lease = $this->maintenance->beginExclusive([
            'operation' => 'cutover_recovery',
            'operation_id' => $journal['operation_id'],
            'backup_id' => $journal['backup_id'],
        ]);
        try {
            $admin = $this->connectDatabase('postgres');
            $recovered = $this->recoverJournalState($admin, $journal);
            if ($recovered['outcome'] === 'completed') {
                $this->invalidateSessions();
            }
            return $recovered;
        } finally {
            $lease->release();
        }
    }

    public function acknowledgeCompletedCutover(string $operationId): void
    {
        $this->assertOperationId($operationId);
        $journal = $this->journal->read();
        if ($journal === null) {
            return;
        }
        if ($journal['operation_id'] !== $operationId || $journal['phase'] !== 'committed') {
            throw new RuntimeException('Cannot acknowledge an incomplete disaster-recovery cutover.');
        }

        $admin = $this->connectDatabase('postgres');
        $current = $journal['current_database'];
        $staging = $journal['staging_database'];
        $previous = $journal['previous_database'];
        if (!$this->databaseExists($admin, $current)
            || $this->databaseExists($admin, $staging)
            || $this->databaseExists($admin, $previous)) {
            throw new RuntimeException('Cannot acknowledge disaster recovery while cutover databases are unresolved.');
        }
        $this->journal->clear();
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array{
     *   notification:array{smtp_password:?string,telegram_bot_token:?string,telegram_proxy_password:?string},
     *   website_endpoints:list<array{id:int,auth:?string,headers:?string}>
     * } $expectedSecrets
     */
    private function cutOver(PDO $admin, array $manifest, array $expectedSecrets): void
    {
        $journal = $this->journal->read();
        if ($journal === null) {
            throw new RuntimeException('Disaster-recovery cutover journal is missing.');
        }
        $currentDatabase = $journal['current_database'];
        $stagingDatabase = $journal['staging_database'];
        $previousDatabase = $journal['previous_database'];

        try {
            $this->setAllowsConnections($admin, $currentDatabase, false);
            $this->terminateDatabaseConnections($admin, $currentDatabase);
            $admin->exec(sprintf(
                'ALTER DATABASE %s RENAME TO %s',
                $this->identifier($currentDatabase),
                $this->identifier($previousDatabase)
            ));
            $this->journal->advance('current_renamed');

            $admin->exec(sprintf(
                'ALTER DATABASE %s RENAME TO %s',
                $this->identifier($stagingDatabase),
                $this->identifier($currentDatabase)
            ));
            $this->journal->advance('staging_renamed');
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

            $this->journal->advance('verified');
            $this->dropDatabaseIfExists($admin, $previousDatabase);
            $this->journal->advance('committed');
        } catch (Throwable $exception) {
            try {
                $latest = $this->journal->read();
                if ($latest !== null) {
                    $recovery = $this->recoverJournalState($admin, $latest);
                    if ($recovery['outcome'] === 'completed') {
                        return;
                    }
                }
            } catch (Throwable $recoveryException) {
                throw new RuntimeException(
                    'Disaster recovery cutover failed and automatic recovery also failed: '
                    . $recoveryException->getMessage(),
                    0,
                    $exception
                );
            }
            throw $exception;
        }
    }

    /**
     * @param array{version:int,operation_id:string,backup_id:string,phase:string,current_database:string,staging_database:string,previous_database:string,result:array<string,mixed>,updated_at:string} $journal
     * @return array{outcome:'completed'|'rolled_back',operation_id:string,result:array<string,mixed>}
     */
    private function recoverJournalState(PDO $admin, array $journal): array
    {
        $current = $journal['current_database'];
        $staging = $journal['staging_database'];
        $previous = $journal['previous_database'];
        $phase = $journal['phase'];

        $currentExists = $this->databaseExists($admin, $current);
        $stagingExists = $this->databaseExists($admin, $staging);
        $previousExists = $this->databaseExists($admin, $previous);

        if (!$currentExists && !$previousExists) {
            throw new RuntimeException(
                'Interrupted disaster recovery has neither the primary nor rollback database; manual recovery is required.'
            );
        }
        if ($currentExists && $previousExists && $stagingExists) {
            throw new RuntimeException(
                'Interrupted disaster recovery has an ambiguous three-database cutover state; manual recovery is required.'
            );
        }

        $verified = in_array($phase, ['verified', 'committed'], true);
        if ($verified && $currentExists) {
            $this->setAllowsConnections($admin, $current, true);
            if ($previousExists) {
                $this->dropDatabaseIfExists($admin, $previous);
            }
            if ($stagingExists) {
                $this->dropDatabaseIfExists($admin, $staging);
            }
            $this->journal->advance('committed');

            return [
                'outcome' => 'completed',
                'operation_id' => $journal['operation_id'],
                'result' => $journal['result'],
            ];
        }

        if (!$currentExists && $previousExists) {
            $admin->exec(sprintf(
                'ALTER DATABASE %s RENAME TO %s',
                $this->identifier($previous),
                $this->identifier($current)
            ));
            $this->setAllowsConnections($admin, $current, true);
            if ($stagingExists) {
                $this->dropDatabaseIfExists($admin, $staging);
            }
            $this->journal->clear();

            return [
                'outcome' => 'rolled_back',
                'operation_id' => $journal['operation_id'],
                'result' => $journal['result'],
            ];
        }

        if ($previousExists) {
            $this->setAllowsConnections($admin, $current, false);
            $this->terminateDatabaseConnections($admin, $current);
            $admin->exec(sprintf(
                'ALTER DATABASE %s RENAME TO %s',
                $this->identifier($current),
                $this->identifier($staging)
            ));
            $admin->exec(sprintf(
                'ALTER DATABASE %s RENAME TO %s',
                $this->identifier($previous),
                $this->identifier($current)
            ));
            $this->setAllowsConnections($admin, $current, true);
            $this->dropDatabaseIfExists($admin, $staging);
            $this->journal->clear();

            return [
                'outcome' => 'rolled_back',
                'operation_id' => $journal['operation_id'],
                'result' => $journal['result'],
            ];
        }

        // The primary exists and the rollback name no longer does. Before verification this is
        // either the untouched B database or a rollback that completed before journal cleanup.
        $this->setAllowsConnections($admin, $current, true);
        if ($stagingExists) {
            $this->dropDatabaseIfExists($admin, $staging);
        }
        $this->journal->clear();

        return [
            'outcome' => 'rolled_back',
            'operation_id' => $journal['operation_id'],
            'result' => $journal['result'],
        ];
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

    private function databaseExists(PDO $admin, string $database): bool
    {
        $statement = $admin->prepare('SELECT EXISTS(SELECT 1 FROM pg_database WHERE datname = :database)');
        $statement->execute(['database' => $database]);
        $value = $statement->fetchColumn();
        return $value === true || $value === 1 || in_array($value, ['1', 't', 'true'], true);
    }

    private function setAllowsConnections(PDO $admin, string $database, bool $allowed): void
    {
        if (!$this->databaseExists($admin, $database)) {
            return;
        }
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

    private function assertOperationId(string $operationId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $operationId) !== 1) {
            throw new RuntimeException('Invalid restore operation ID.');
        }
    }
}
