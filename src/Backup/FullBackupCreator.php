<?php

declare(strict_types=1);

namespace App\Backup;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class FullBackupCreator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BackupSecretCatalog $secrets,
        private readonly BackupManifest $manifest,
        private readonly PostgresBackupTool $postgres,
        private readonly BackupContainer $container,
        private readonly string $workspaceRoot
    ) {
    }

    /** @return array<string, mixed> */
    public function create(string $destination, string $password): array
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Cannot create full backup inside an existing transaction.');
        }
        $this->ensureWorkspaceRoot();
        $backupId = $this->uuidV4();
        $workspace = rtrim($this->workspaceRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'create-' . $backupId;
        if (!mkdir($workspace, 0700)) {
            throw new RuntimeException('Cannot create backup workspace.');
        }

        $manifestPath = $workspace . '/manifest.json';
        $databasePath = $workspace . '/database.pgdump';
        $secretsPath = $workspace . '/secrets.json';
        $transactionOpen = false;

        try {
            $this->pdo->beginTransaction();
            $transactionOpen = true;
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $snapshot = $this->pdo->query('SELECT pg_export_snapshot()')?->fetchColumn();
            if (!is_string($snapshot) || $snapshot === '') {
                throw new RuntimeException('Cannot export PostgreSQL backup snapshot.');
            }

            $secretPayload = $this->secrets->export();
            $this->writeJson($secretsPath, $secretPayload);
            $this->postgres->dumpSnapshot($snapshot, $databasePath);

            $manifest = $this->manifest->build(
                $backupId,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                [
                    'database.pgdump' => $databasePath,
                    'secrets.json' => $secretsPath,
                ]
            );
            $this->writeFile($manifestPath, $this->manifest->encode($manifest));

            $this->pdo->commit();
            $transactionOpen = false;

            $this->container->write($destination, $password, [
                'manifest.json' => $manifestPath,
                'database.pgdump' => $databasePath,
                'secrets.json' => $secretsPath,
            ]);

            return $manifest;
        } catch (Throwable $exception) {
            if ($transactionOpen && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            @unlink($destination);
            throw $exception;
        } finally {
            $this->removeTree($workspace);
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode backup secret payload.', 0, $exception);
        }
        $this->writeFile($path, $json);
    }

    private function writeFile(string $path, string $contents): void
    {
        $written = @file_put_contents($path, $contents, LOCK_EX);
        if ($written === false || $written !== strlen($contents)) {
            @unlink($path);
            throw new RuntimeException('Cannot write backup workspace file.');
        }
        @chmod($path, 0600);
    }

    private function ensureWorkspaceRoot(): void
    {
        if (!is_dir($this->workspaceRoot)) {
            if (!mkdir($this->workspaceRoot, 0700, true) && !is_dir($this->workspaceRoot)) {
                throw new RuntimeException('Cannot create backup workspace root.');
            }
        }
        if (!is_writable($this->workspaceRoot)) {
            throw new RuntimeException('Backup workspace root is not writable.');
        }
        @chmod($this->workspaceRoot, 0700);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
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
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
