<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

final class PostgresBackupTool
{
    /** @param array<string, string> $databaseEnvironment */
    public function __construct(private readonly array $databaseEnvironment)
    {
        foreach (['DB_HOST', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
            if (trim($this->databaseEnvironment[$required] ?? '') === '') {
                throw new RuntimeException($required . ' is required for backup tooling.');
            }
        }
    }

    public function dumpSnapshot(string $snapshot, string $destination): void
    {
        if ($snapshot === '' || str_contains($snapshot, "\0")) {
            throw new RuntimeException('Invalid PostgreSQL snapshot identifier.');
        }
        $this->run([
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--snapshot=' . $snapshot,
            '--file=' . $destination,
        ]);
        if (!is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException('pg_dump did not create a usable backup file.');
        }
        @chmod($destination, 0600);
    }

    public function assertReadableDump(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('PostgreSQL dump is not readable.');
        }
        $this->run(['pg_restore', '--list', $path]);
    }

    public function restore(string $path, string $database): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/', $database) !== 1) {
            throw new RuntimeException('Invalid PostgreSQL restore database name.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('PostgreSQL dump is not readable.');
        }
        $this->run([
            'pg_restore',
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            '--dbname=' . $database,
            $path,
        ]);
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        $environment = array_merge($_ENV, [
            'PGHOST' => $this->databaseEnvironment['DB_HOST'],
            'PGPORT' => $this->databaseEnvironment['DB_PORT'] ?? '5432',
            'PGDATABASE' => $this->databaseEnvironment['DB_NAME'],
            'PGUSER' => $this->databaseEnvironment['DB_USERNAME'],
            'PGPASSWORD' => $this->databaseEnvironment['DB_PASSWORD'],
            'PGSSLMODE' => $this->databaseEnvironment['DB_SSLMODE'] ?? 'prefer',
        ]);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, $environment, [
            'bypass_shell' => true,
        ]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PostgreSQL backup tool.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $message = trim(is_string($stderr) ? $stderr : '');
            if ($message === '') {
                $message = trim(is_string($stdout) ? $stdout : '');
            }
            throw new RuntimeException(
                'PostgreSQL backup tool failed' . ($message === '' ? '.' : ': ' . $message)
            );
        }
    }
}
