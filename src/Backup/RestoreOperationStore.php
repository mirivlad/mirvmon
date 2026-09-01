<?php

declare(strict_types=1);

namespace App\Backup;

use JsonException;
use RuntimeException;
use Throwable;

final class RestoreOperationStore
{
    private const MAX_AGE_SECONDS = 3600;

    public function __construct(
        private readonly string $root,
        private readonly int $maximumUploadBytes = 8589934592
    ) {
        if ($this->maximumUploadBytes < 1) {
            throw new RuntimeException('Restore upload limit must be positive.');
        }
    }

    /** @return array{id:string,next_chunk:int,received_bytes:int,total_bytes:int} */
    public function begin(string $filename, int $totalBytes): array
    {
        $this->ensureRoot();
        $this->purgeExpired();
        if ($totalBytes < 1 || $totalBytes > $this->maximumUploadBytes) {
            throw new RuntimeException('Backup upload size is outside the configured limit.');
        }
        $filename = trim($filename);
        if ($filename === '' || strlen($filename) > 255 || str_contains($filename, "\0")) {
            throw new RuntimeException('Backup filename is invalid.');
        }

        $id = bin2hex(random_bytes(16));
        $directory = $this->operationDirectory($id);
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create restore upload directory.');
        }
        @chmod($directory, 0700);
        $this->writeState($id, [
            'id' => $id,
            'status' => 'uploading',
            'filename' => basename($filename),
            'total_bytes' => $totalBytes,
            'received_bytes' => 0,
            'next_chunk' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $part = $this->partPath($id);
        if (file_put_contents($part, '') === false) {
            $this->removeTree($directory);
            throw new RuntimeException('Cannot initialize restore upload file.');
        }
        @chmod($part, 0600);

        return [
            'id' => $id,
            'next_chunk' => 0,
            'received_bytes' => 0,
            'total_bytes' => $totalBytes,
        ];
    }

    /**
     * @return array{id:string,next_chunk:int,received_bytes:int,total_bytes:int,complete:bool}
     */
    public function append(string $id, int $index, string $chunkPath): array
    {
        $this->assertId($id);
        if ($index < 0 || !is_file($chunkPath) || !is_readable($chunkPath)) {
            throw new RuntimeException('Restore upload chunk is invalid.');
        }
        $chunkSize = filesize($chunkPath);
        if ($chunkSize === false || $chunkSize < 1 || $chunkSize > 786432) {
            throw new RuntimeException('Restore upload chunk size is invalid.');
        }

        return $this->withLock($id, function () use ($id, $index, $chunkPath, $chunkSize): array {
            $state = $this->readState($id);
            if (($state['status'] ?? null) !== 'uploading') {
                throw new RuntimeException('Restore upload is not accepting chunks.');
            }
            if (($state['next_chunk'] ?? null) !== $index) {
                throw new RuntimeException('Restore upload chunk is out of sequence.');
            }
            $received = $state['received_bytes'] ?? null;
            $total = $state['total_bytes'] ?? null;
            if (!is_int($received) || !is_int($total)
                || $received < 0 || $total < 1
                || $received + $chunkSize > $total) {
                throw new RuntimeException('Restore upload byte count is invalid.');
            }

            $input = fopen($chunkPath, 'rb');
            $output = fopen($this->partPath($id), 'ab');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('Cannot append restore upload chunk.');
            }
            try {
                $copied = stream_copy_to_stream($input, $output);
                if ($copied !== $chunkSize) {
                    throw new RuntimeException('Restore upload chunk was not written completely.');
                }
                fflush($output);
            } finally {
                fclose($input);
                fclose($output);
            }

            $received += $chunkSize;
            $state['received_bytes'] = $received;
            $state['next_chunk'] = $index + 1;
            $state['updated_at'] = time();
            $complete = $received === $total;
            if ($complete) {
                $final = $this->backupPath($id);
                if (!rename($this->partPath($id), $final)) {
                    throw new RuntimeException('Cannot finalize restore upload.');
                }
                @chmod($final, 0600);
                $state['status'] = 'uploaded';
            }
            $this->writeState($id, $state);

            return [
                'id' => $id,
                'next_chunk' => $index + 1,
                'received_bytes' => $received,
                'total_bytes' => $total,
                'complete' => $complete,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function uploaded(string $id): array
    {
        $state = $this->readState($id);
        if (($state['status'] ?? null) !== 'uploaded' || !is_file($this->backupPath($id))) {
            throw new RuntimeException('Restore upload is incomplete.');
        }
        return $state + ['backup_path' => $this->backupPath($id)];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $warnings
     */
    public function markReady(string $id, array $manifest, array $warnings, string $workspace): void
    {
        $this->withLock($id, function () use ($id, $manifest, $warnings, $workspace): void {
            $state = $this->readState($id);
            if (($state['status'] ?? null) !== 'uploaded') {
                throw new RuntimeException('Restore operation is not ready for preflight result.');
            }
            if (!is_dir($workspace)) {
                throw new RuntimeException('Restore preflight workspace is missing.');
            }
            $state['status'] = 'ready';
            $state['manifest'] = $manifest;
            $state['warnings'] = array_values($warnings);
            $state['workspace'] = $workspace;
            $state['updated_at'] = time();
            $this->writeState($id, $state);
        });
    }

    /** @return array<string, mixed> */
    public function ready(string $id): array
    {
        $state = $this->readState($id);
        if (($state['status'] ?? null) !== 'ready') {
            throw new RuntimeException('Restore operation has not passed preflight.');
        }
        $workspace = $state['workspace'] ?? null;
        $manifest = $state['manifest'] ?? null;
        if (!is_string($workspace) || !is_dir($workspace) || !is_array($manifest)) {
            throw new RuntimeException('Restore operation state is incomplete.');
        }
        return $state;
    }

    public function finish(string $id): void
    {
        $this->assertId($id);
        $this->removeTree($this->operationDirectory($id));
    }

    public function purgeExpired(): void
    {
        $this->ensureRoot();
        $now = time();
        foreach (scandir($this->root) ?: [] as $entry) {
            if (!preg_match('/^[a-f0-9]{32}$/', $entry)) {
                continue;
            }
            $directory = $this->root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($directory)) {
                continue;
            }
            try {
                $state = $this->readState($entry);
                $updated = $state['updated_at'] ?? null;
                if (!is_int($updated) || $updated < $now - self::MAX_AGE_SECONDS) {
                    $workspace = $state['workspace'] ?? null;
                    if (is_string($workspace) && $this->workspaceIsOwned($entry, $workspace)) {
                        $this->removeTree($workspace);
                    }
                    $this->removeTree($directory);
                }
            } catch (Throwable) {
                $mtime = filemtime($directory);
                if ($mtime !== false && $mtime < $now - self::MAX_AGE_SECONDS) {
                    $this->removeTree($directory);
                }
            }
        }
    }

    public function workspacePath(string $id): string
    {
        $this->assertId($id);
        return dirname($this->root) . DIRECTORY_SEPARATOR . 'work' . DIRECTORY_SEPARATOR . 'restore-' . $id;
    }

    /** @return array<string, mixed> */
    private function readState(string $id): array
    {
        $this->assertId($id);
        $json = file_get_contents($this->statePath($id));
        if ($json === false) {
            throw new RuntimeException('Restore operation does not exist.');
        }
        try {
            $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Restore operation state is corrupt.', 0, $exception);
        }
        if (!is_array($state) || ($state['id'] ?? null) !== $id) {
            throw new RuntimeException('Restore operation state is invalid.');
        }
        return $state;
    }

    /** @param array<string, mixed> $state */
    private function writeState(string $id, array $state): void
    {
        $this->assertId($id);
        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode restore operation state.', 0, $exception);
        }
        $path = $this->statePath($id);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write restore operation state.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot publish restore operation state.');
        }
        @chmod($path, 0600);
    }

    private function withLock(string $id, callable $callback): mixed
    {
        $this->assertId($id);
        $handle = fopen($this->operationDirectory($id) . DIRECTORY_SEPARATOR . 'operation.lock', 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open restore operation lock.');
        }
        @chmod($this->operationDirectory($id) . DIRECTORY_SEPARATOR . 'operation.lock', 0600);
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock restore operation.');
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root)) {
            if (!mkdir($this->root, 0700, true) && !is_dir($this->root)) {
                throw new RuntimeException('Cannot create restore operation root.');
            }
        }
        if (!is_writable($this->root)) {
            throw new RuntimeException('Restore operation root is not writable.');
        }
        @chmod($this->root, 0700);
        $work = dirname($this->root) . DIRECTORY_SEPARATOR . 'work';
        if (!is_dir($work) && !mkdir($work, 0700, true) && !is_dir($work)) {
            throw new RuntimeException('Cannot create restore workspace root.');
        }
        @chmod($work, 0700);
    }

    private function assertId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new RuntimeException('Restore operation ID is invalid.');
        }
    }

    private function operationDirectory(string $id): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id;
    }

    private function statePath(string $id): string
    {
        return $this->operationDirectory($id) . DIRECTORY_SEPARATOR . 'state.json';
    }

    private function partPath(string $id): string
    {
        return $this->operationDirectory($id) . DIRECTORY_SEPARATOR . 'backup.part';
    }

    private function backupPath(string $id): string
    {
        return $this->operationDirectory($id) . DIRECTORY_SEPARATOR . 'backup.mmbak';
    }

    private function workspaceIsOwned(string $id, string $workspace): bool
    {
        return $workspace === $this->workspacePath($id);
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
