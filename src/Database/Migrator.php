<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    private const LOCK_NAME = 'mirvmon-schema-migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory
    ) {
    }

    /**
     * @return list<string> Applied migration filenames.
     */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->query(
            "SELECT pg_advisory_lock(hashtext('" . self::LOCK_NAME . "'))"
        );

        try {
            return $this->applyPendingMigrations();
        } finally {
            $this->pdo->query(
                "SELECT pg_advisory_unlock(hashtext('" . self::LOCK_NAME . "'))"
            );
        }
    }

    /**
     * @return list<string>
     */
    public static function migrationFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('Migration directory does not exist: ' . $directory);
        }

        $files = glob(rtrim($directory, '/') . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('Migration directory cannot be read: ' . $directory);
        }

        $versions = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $filename, $matches) !== 1) {
                throw new RuntimeException('Invalid migration filename: ' . $filename);
            }

            if (isset($versions[$matches[1]])) {
                throw new RuntimeException('Duplicate migration version: ' . $matches[1]);
            }
            $versions[$matches[1]] = true;
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function applyPendingMigrations(): array
    {
        $rows = $this->pdo->query(
            'SELECT version, checksum FROM schema_migrations ORDER BY version'
        )->fetchAll();
        $applied = [];

        foreach ($rows as $row) {
            $applied[(string) $row['version']] = (string) $row['checksum'];
        }

        $newlyApplied = [];
        foreach (self::migrationFiles($this->directory) as $file) {
            $version = basename($file);
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new RuntimeException('Cannot calculate migration checksum: ' . $version);
            }

            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    throw new RuntimeException('Applied migration was changed: ' . $version);
                }
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Cannot read migration: ' . $version);
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, checksum)
                     VALUES (:version, :checksum)'
                );
                $statement->execute([
                    'version' => $version,
                    'checksum' => $checksum,
                ]);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }

            $newlyApplied[] = $version;
        }

        return $newlyApplied;
    }
}
