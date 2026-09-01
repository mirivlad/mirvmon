<?php

declare(strict_types=1);

namespace App\Backup;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class BackupPreflight
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BackupContainer $container,
        private readonly BackupManifest $manifest,
        private readonly BackupSecretCatalog $secrets,
        private readonly PostgresBackupTool $postgres
    ) {
    }

    /**
     * Performs restore preflight without modifying the MirvMon database.
     * The caller owns cleanup of $workspace after success; failures clean their partial extraction.
     *
     * @return array{manifest:array<string,mixed>,warnings:list<string>,workspace:string}
     */
    public function run(string $backupPath, string $password, string $workspace): array
    {
        if (file_exists($workspace)) {
            throw new RuntimeException('Restore preflight workspace already exists.');
        }
        if (!mkdir($workspace, 0700, true)) {
            throw new RuntimeException('Cannot create restore preflight workspace.');
        }
        @chmod($workspace, 0700);

        try {
            $this->container->extract($backupPath, $password, $workspace);
            $manifestPath = $workspace . '/manifest.json';
            $manifestJson = file_get_contents($manifestPath);
            if ($manifestJson === false) {
                throw new RuntimeException('Cannot read extracted backup manifest.');
            }
            $manifest = $this->manifest->decode($manifestJson);
            $this->manifest->validate($manifest, $workspace);

            $secretJson = file_get_contents($workspace . '/secrets.json');
            if ($secretJson === false) {
                throw new RuntimeException('Cannot read extracted backup secret payload.');
            }
            try {
                $secretPayload = json_decode($secretJson, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Invalid backup secret payload JSON.', 0, $exception);
            }
            if (!is_array($secretPayload)) {
                throw new RuntimeException('Invalid backup secret payload.');
            }
            $this->secrets->validate($secretPayload);

            $this->postgres->assertReadableDump($workspace . '/database.pgdump');

            return [
                'manifest' => $manifest,
                'warnings' => $this->versionWarnings($manifest),
                'workspace' => $workspace,
            ];
        } catch (Throwable $exception) {
            $this->removeTree($workspace);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $manifest @return list<string> */
    private function versionWarnings(array $manifest): array
    {
        $source = $manifest['source'];
        if (!is_array($source)) {
            throw new RuntimeException('Backup source metadata is missing.');
        }
        $current = $this->pdo->query(
            "SELECT current_setting('server_version') AS postgres_version,
                    current_setting('server_version_num') AS postgres_version_num,
                    (SELECT extversion FROM pg_extension WHERE extname = 'timescaledb') AS timescale_version"
        )?->fetch();
        if (!is_array($current) || !is_string($current['timescale_version'] ?? null)) {
            throw new RuntimeException('Cannot read current PostgreSQL/TimescaleDB versions.');
        }

        $warnings = [];
        $sourcePg = (int) $source['postgres_version_num'];
        $currentPg = (int) $current['postgres_version_num'];
        if (intdiv($sourcePg, 10000) !== intdiv($currentPg, 10000)) {
            $warnings[] = sprintf(
                'PostgreSQL major version differs: backup %s, current %s. Restore will be attempted in staging.',
                (string) $source['postgres_version'],
                (string) $current['postgres_version']
            );
        } elseif ((string) $source['postgres_version'] !== (string) $current['postgres_version']) {
            $warnings[] = sprintf(
                'PostgreSQL version differs: backup %s, current %s. Restore will be attempted in staging.',
                (string) $source['postgres_version'],
                (string) $current['postgres_version']
            );
        }

        if ((string) $source['timescale_version'] !== (string) $current['timescale_version']) {
            $warnings[] = sprintf(
                'TimescaleDB version differs: backup %s, current %s. Restore will be attempted in staging.',
                (string) $source['timescale_version'],
                (string) $current['timescale_version']
            );
        }

        return $warnings;
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
