<?php

declare(strict_types=1);

namespace App\Backup;

use App\Security\SecretCipher;
use JsonException;
use RuntimeException;
use Throwable;

final class BackupOperationStore
{
    private const ACTIVE_MAX_AGE_SECONDS = 21600;
    private const TERMINAL_MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly string $root,
        private readonly SecretCipher $handoffCipher
    ) {
    }

    /** @return array{id:string,status:string,filename:string,created_at:int,updated_at:int} */
    public function begin(string $password, string $filename): array
    {
        if (strlen($password) < 8 || strlen($password) > 1024) {
            throw new RuntimeException('Backup password length is invalid.');
        }
        $filename = trim($filename);
        if ($filename === '' || strlen($filename) > 255 || str_contains($filename, "\0") || basename($filename) !== $filename) {
            throw new RuntimeException('Backup filename is invalid.');
        }

        $this->ensureRoot();
        $this->purgeExpired();
        $id = bin2hex(random_bytes(16));
        $directory = $this->operationDirectory($id);
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create backup operation directory.');
        }
        @chmod($directory, 0700);
        $now = time();
        $state = [
            'id' => $id,
            'status' => 'queued',
            'filename' => $filename,
            'password_handoff' => $this->handoffCipher->encrypt($password),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->writeState($id, $state);
        sodium_memzero($password);

        return $this->sanitize($state);
    }

    /** @return array<string,mixed> */
    public function operation(string $id): array
    {
        return $this->sanitize($this->readState($id));
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 10): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new RuntimeException('Backup operation list limit is invalid.');
        }
        $this->ensureRoot();
        $this->purgeExpired();
        $operations = [];
        foreach ($this->operationIds() as $id) {
            try {
                $operations[] = $this->sanitize($this->readState($id));
            } catch (Throwable) {
                continue;
            }
        }
        usort($operations, static function (array $left, array $right): int {
            $created = ($right['created_at'] ?? 0) <=> ($left['created_at'] ?? 0);
            return $created !== 0 ? $created : strcmp((string) ($right['id'] ?? ''), (string) ($left['id'] ?? ''));
        });
        return array_slice($operations, 0, $limit);
    }

    /** @return array<string,mixed>|null */
    public function claimNext(string $workerId): ?array
    {
        $workerId = trim($workerId);
        if ($workerId === '' || strlen($workerId) > 120 || str_contains($workerId, "\0")) {
            throw new RuntimeException('Backup worker ID is invalid.');
        }
        $this->ensureRoot();
        foreach ($this->operationIds() as $id) {
            $claimed = $this->withLock($id, function () use ($id, $workerId): ?array {
                $state = $this->readState($id);
                if (($state['status'] ?? null) !== 'queued') {
                    return null;
                }
                $this->assertHandoff($state);
                $state['status'] = 'running';
                $state['worker_id'] = $workerId;
                $state['started_at'] = time();
                $state['updated_at'] = time();
                $this->writeState($id, $state);
                return $state;
            });
            if (is_array($claimed)) {
                return $claimed;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $claimed */
    public function passwordForClaim(array $claimed): string
    {
        if (($claimed['status'] ?? null) !== 'running') {
            throw new RuntimeException('Backup password handoff is not attached to a running job.');
        }
        $this->assertHandoff($claimed);
        $password = $this->handoffCipher->decrypt((string) $claimed['password_handoff']);
        if (strlen($password) < 8 || strlen($password) > 1024) {
            sodium_memzero($password);
            throw new RuntimeException('Backup password handoff is invalid.');
        }
        return $password;
    }

    public function outputPath(string $id): string
    {
        $this->assertId($id);
        return $this->operationDirectory($id) . DIRECTORY_SEPARATOR . 'backup.mmbak';
    }

    public function workspaceRoot(string $id): string
    {
        $this->assertId($id);
        return $this->operationDirectory($id) . DIRECTORY_SEPARATOR . 'work';
    }

    /** @param array<string,mixed> $manifest */
    public function markSucceeded(string $id, array $manifest): void
    {
        $this->withLock($id, function () use ($id, $manifest): void {
            $state = $this->readState($id);
            if (($state['status'] ?? null) !== 'running') {
                throw new RuntimeException('Backup operation cannot complete from its current state.');
            }
            $path = $this->outputPath($id);
            $size = is_file($path) ? filesize($path) : false;
            if ($size === false || $size < 1) {
                throw new RuntimeException('Completed backup archive is missing.');
            }
            $state['status'] = 'succeeded';
            $state['manifest'] = $manifest;
            $state['size'] = $size;
            $state['finished_at'] = time();
            $state['updated_at'] = time();
            unset($state['password_handoff'], $state['worker_id'], $state['error_code']);
            $this->writeState($id, $state);
            $this->cleanupTemporaryFiles($id);
        });
    }

    public function markFailed(string $id, string $errorCode = 'backup_failed'): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/', $errorCode) !== 1) {
            throw new RuntimeException('Backup failure code is invalid.');
        }
        $this->withLock($id, function () use ($id, $errorCode): void {
            $state = $this->readState($id);
            if (!in_array($state['status'] ?? null, ['queued', 'running', 'failed'], true)) {
                throw new RuntimeException('Backup operation cannot fail from its current state.');
            }
            $state['status'] = 'failed';
            $state['error_code'] = $errorCode;
            $state['finished_at'] = time();
            $state['updated_at'] = time();
            unset($state['password_handoff'], $state['worker_id']);
            $this->writeState($id, $state);
            @unlink($this->outputPath($id));
            $this->cleanupTemporaryFiles($id);
        });
    }

    /** @return array<string,mixed> */
    public function download(string $id): array
    {
        $state = $this->readState($id);
        if (($state['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException('Backup operation is not ready for download.');
        }
        $path = $this->outputPath($id);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Backup archive is no longer available.');
        }
        return $this->sanitize($state) + ['path' => $path];
    }

    public function requeueInterrupted(): void
    {
        $this->ensureRoot();
        foreach ($this->operationIds() as $id) {
            $this->withLock($id, function () use ($id): void {
                $state = $this->readState($id);
                if (($state['status'] ?? null) !== 'running') {
                    return;
                }
                try {
                    $this->assertHandoff($state);
                } catch (Throwable) {
                    $state['status'] = 'failed';
                    $state['error_code'] = 'interrupted_password_handoff_missing';
                    $state['finished_at'] = time();
                    unset($state['password_handoff']);
                    @unlink($this->outputPath($id));
                    $this->cleanupTemporaryFiles($id);
                    $state['updated_at'] = time();
                    $this->writeState($id, $state);
                    return;
                }
                @unlink($this->outputPath($id));
                $this->cleanupTemporaryFiles($id);
                $state['status'] = 'queued';
                $state['recovered_after_worker_restart'] = true;
                $state['updated_at'] = time();
                unset($state['worker_id'], $state['started_at']);
                $this->writeState($id, $state);
            });
        }
    }

    public function purgeExpired(): void
    {
        $this->ensureRoot();
        $now = time();
        foreach ($this->operationIds() as $id) {
            $directory = $this->operationDirectory($id);
            try {
                $state = $this->readState($id);
                $status = $state['status'] ?? null;
                if ($status === 'running') {
                    continue;
                }
                $updated = $state['updated_at'] ?? null;
                $maximumAge = in_array($status, ['succeeded', 'failed'], true)
                    ? self::TERMINAL_MAX_AGE_SECONDS
                    : self::ACTIVE_MAX_AGE_SECONDS;
                if (!is_int($updated) || $updated < $now - $maximumAge) {
                    $this->removeTree($directory);
                }
            } catch (Throwable) {
                $mtime = filemtime($directory);
                if ($mtime !== false && $mtime < $now - self::ACTIVE_MAX_AGE_SECONDS) {
                    $this->removeTree($directory);
                }
            }
        }
    }

    /** @param array<string,mixed> $state */
    private function assertHandoff(array $state): void
    {
        $handoff = $state['password_handoff'] ?? null;
        if (!is_string($handoff) || !str_starts_with($handoff, 'v1:') || strlen($handoff) > 4096) {
            throw new RuntimeException('Backup password handoff is missing or invalid.');
        }
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function sanitize(array $state): array
    {
        unset($state['password_handoff'], $state['worker_id']);
        if (in_array($state['status'] ?? null, ['succeeded', 'failed'], true)) {
            $updated = $state['updated_at'] ?? null;
            if (is_int($updated)) {
                $state['expires_at'] = $updated + self::TERMINAL_MAX_AGE_SECONDS;
            }
        }
        return $state;
    }

    /** @return list<string> */
    private function operationIds(): array
    {
        $ids = [];
        foreach (scandir($this->root) ?: [] as $entry) {
            if (preg_match('/^[a-f0-9]{32}$/', $entry) === 1 && is_dir($this->operationDirectory($entry))) {
                $ids[] = $entry;
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return array<string,mixed> */
    private function readState(string $id): array
    {
        $this->assertId($id);
        $json = @file_get_contents($this->statePath($id));
        if ($json === false) {
            throw new RuntimeException('Backup operation does not exist.');
        }
        try {
            $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Backup operation state is corrupt.', 0, $exception);
        }
        if (!is_array($state) || ($state['id'] ?? null) !== $id) {
            throw new RuntimeException('Backup operation state is invalid.');
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function writeState(string $id, array $state): void
    {
        $this->assertId($id);
        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode backup operation state.', 0, $exception);
        }
        $path = $this->statePath($id);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write backup operation state.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot publish backup operation state.');
        }
        @chmod($path, 0600);
    }

    private function withLock(string $id, callable $callback): mixed
    {
        $this->assertId($id);
        $handle = @fopen($this->operationDirectory($id) . DIRECTORY_SEPARATOR . 'operation.lock', 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open backup operation lock.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock backup operation.');
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new RuntimeException('Cannot create backup operation root.');
        }
        if (!is_writable($this->root)) {
            throw new RuntimeException('Backup operation root is not writable.');
        }
        @chmod($this->root, 0700);
    }

    private function assertId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new RuntimeException('Backup operation ID is invalid.');
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

    private function cleanupTemporaryFiles(string $id): void
    {
        foreach (glob($this->outputPath($id) . '.tmp-*') ?: [] as $path) {
            @unlink($path);
        }
        $this->removeTree($this->workspaceRoot($id));
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
