<?php

declare(strict_types=1);

namespace App\Backup;

use App\Database\Migrator;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use PDO;
use RuntimeException;

final class BackupManifest
{
    private const VERSION = 1;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationDirectory,
        private readonly string $appVersion = 'development',
        private readonly ?string $appCommit = null
    ) {
    }

    /**
     * @param array{database.pgdump:string,secrets.json:string} $recordPaths
     * @return array<string, mixed>
     */
    public function build(
        string $backupId,
        DateTimeImmutable $createdAt,
        array $recordPaths
    ): array {
        if (preg_match('/^[a-f0-9-]{36}$/', $backupId) !== 1) {
            throw new RuntimeException('Invalid backup UUID.');
        }

        $database = $this->databaseVersion();
        $migrations = $this->appliedMigrations();

        return [
            'manifest_version' => self::VERSION,
            'backup_type' => 'full',
            'backup_id' => $backupId,
            'created_at' => $createdAt->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            'source' => [
                'mirvmon_version' => $this->appVersion,
                'mirvmon_commit' => $this->appCommit,
                'postgres_version' => $database['postgres_version'],
                'postgres_version_num' => $database['postgres_version_num'],
                'timescale_version' => $database['timescale_version'],
            ],
            'schema_migrations' => $migrations,
            'secret_payload_version' => 1,
            'records' => [
                'database.pgdump' => $this->fileDescriptor($recordPaths['database.pgdump']),
                'secrets.json' => $this->fileDescriptor($recordPaths['secrets.json']),
            ],
            'stats' => [
                'users' => $this->count('users'),
                'servers' => $this->count('servers'),
                'websites' => $this->count('websites'),
            ],
        ];
    }

    /** @param array<string, mixed> $manifest */
    public function encode(array $manifest): string
    {
        $this->validate($manifest);
        try {
            return json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode backup manifest.', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    public function decode(string $json): array
    {
        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid backup manifest JSON.', 0, $exception);
        }
        if (!is_array($manifest)) {
            throw new RuntimeException('Invalid backup manifest.');
        }
        $this->validate($manifest);
        return $manifest;
    }

    /**
     * Validates manifest shape, migration checksums known to the current application and record digests.
     * Database/Timescale version differences are intentionally not rejected here; preflight reports them as warnings.
     *
     * @param array<string, mixed> $manifest
     */
    public function validate(array $manifest, ?string $extractedDirectory = null): void
    {
        if (($manifest['manifest_version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Unsupported backup manifest version.');
        }
        if (($manifest['backup_type'] ?? null) !== 'full') {
            throw new RuntimeException('Only full disaster-recovery backups are supported.');
        }
        if (!is_string($manifest['backup_id'] ?? null)
            || preg_match('/^[a-f0-9-]{36}$/', (string) $manifest['backup_id']) !== 1) {
            throw new RuntimeException('Invalid backup manifest UUID.');
        }
        if (!is_string($manifest['created_at'] ?? null)) {
            throw new RuntimeException('Invalid backup creation time.');
        }
        try {
            new DateTimeImmutable((string) $manifest['created_at']);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Invalid backup creation time.', 0, $exception);
        }

        $source = $manifest['source'] ?? null;
        if (!is_array($source)
            || !is_string($source['mirvmon_version'] ?? null)
            || !is_string($source['postgres_version'] ?? null)
            || !is_int($source['postgres_version_num'] ?? null)
            || !is_string($source['timescale_version'] ?? null)) {
            throw new RuntimeException('Invalid backup source version metadata.');
        }
        if (($source['mirvmon_commit'] ?? null) !== null
            && !is_string($source['mirvmon_commit'])) {
            throw new RuntimeException('Invalid MirvMon source commit metadata.');
        }
        if (($manifest['secret_payload_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported backup secret payload version.');
        }

        $this->validateMigrations($manifest['schema_migrations'] ?? null);
        $records = $manifest['records'] ?? null;
        if (!is_array($records) || array_keys($records) !== ['database.pgdump', 'secrets.json']) {
            throw new RuntimeException('Invalid backup manifest record list.');
        }
        foreach ($records as $name => $descriptor) {
            if (!is_array($descriptor)
                || array_keys($descriptor) !== ['size', 'sha256']
                || !is_int($descriptor['size'])
                || $descriptor['size'] < 0
                || !is_string($descriptor['sha256'])
                || preg_match('/^[a-f0-9]{64}$/', $descriptor['sha256']) !== 1) {
                throw new RuntimeException('Invalid backup record descriptor: ' . $name);
            }
            if ($extractedDirectory !== null) {
                $path = $extractedDirectory . DIRECTORY_SEPARATOR . $name;
                if (!is_file($path)) {
                    throw new RuntimeException('Backup record is missing: ' . $name);
                }
                $size = filesize($path);
                $sha256 = hash_file('sha256', $path);
                if ($size === false || $sha256 === false
                    || $size !== $descriptor['size']
                    || !hash_equals($descriptor['sha256'], $sha256)) {
                    throw new RuntimeException('Backup record checksum mismatch: ' . $name);
                }
            }
        }

        $stats = $manifest['stats'] ?? null;
        if (!is_array($stats)) {
            throw new RuntimeException('Invalid backup statistics.');
        }
        foreach (['users', 'servers', 'websites'] as $key) {
            if (!is_int($stats[$key] ?? null) || $stats[$key] < 0) {
                throw new RuntimeException('Invalid backup statistic: ' . $key);
            }
        }
    }

    /** @return array{postgres_version:string,postgres_version_num:int,timescale_version:string} */
    private function databaseVersion(): array
    {
        $row = $this->pdo->query(
            "SELECT current_setting('server_version') AS postgres_version,
                    current_setting('server_version_num') AS postgres_version_num,
                    (SELECT extversion FROM pg_extension WHERE extname = 'timescaledb') AS timescale_version"
        )?->fetch();
        if (!is_array($row) || !is_string($row['timescale_version'] ?? null)) {
            throw new RuntimeException('Cannot read PostgreSQL/TimescaleDB version metadata.');
        }
        return [
            'postgres_version' => (string) $row['postgres_version'],
            'postgres_version_num' => (int) $row['postgres_version_num'],
            'timescale_version' => (string) $row['timescale_version'],
        ];
    }

    /** @return list<array{version:string,checksum:string}> */
    private function appliedMigrations(): array
    {
        $rows = $this->pdo->query(
            'SELECT version, checksum FROM schema_migrations ORDER BY version'
        )?->fetchAll() ?? [];
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'version' => (string) $row['version'],
                'checksum' => (string) $row['checksum'],
            ];
        }
        return $result;
    }

    private function count(string $table): int
    {
        if (!in_array($table, ['users', 'servers', 'websites'], true)) {
            throw new RuntimeException('Unsupported backup statistics table.');
        }
        return (int) ($this->pdo->query('SELECT count(*) FROM ' . $table)?->fetchColumn() ?: 0);
    }

    /** @return array{size:int,sha256:string} */
    private function fileDescriptor(string $path): array
    {
        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if ($size === false || $sha256 === false) {
            throw new RuntimeException('Cannot hash backup record: ' . basename($path));
        }
        return ['size' => $size, 'sha256' => $sha256];
    }

    private function validateMigrations(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Invalid backup migration manifest.');
        }

        $known = [];
        foreach (Migrator::migrationFiles($this->migrationDirectory) as $file) {
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new RuntimeException('Cannot hash current migration: ' . basename($file));
            }
            $known[basename($file)] = $checksum;
        }

        $previous = '';
        foreach ($value as $entry) {
            if (!is_array($entry)
                || array_keys($entry) !== ['version', 'checksum']
                || !is_string($entry['version'])
                || !is_string($entry['checksum'])
                || preg_match('/^[a-f0-9]{64}$/', $entry['checksum']) !== 1) {
                throw new RuntimeException('Invalid backup migration entry.');
            }
            $version = $entry['version'];
            if ($version <= $previous || !isset($known[$version])) {
                throw new RuntimeException('Backup uses an unknown or unordered schema migration: ' . $version);
            }
            if (!hash_equals($known[$version], $entry['checksum'])) {
                throw new RuntimeException('Backup migration checksum differs from this MirvMon build: ' . $version);
            }
            $previous = $version;
        }
    }
}
