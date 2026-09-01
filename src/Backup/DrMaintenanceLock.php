<?php

declare(strict_types=1);

namespace App\Backup;

use JsonException;
use RuntimeException;
use Throwable;

final class DrMaintenanceLock
{
    public function __construct(private readonly string $root)
    {
    }

    /** @phpstan-impure */
    public function isActive(): bool
    {
        return is_file($this->markerPath());
    }

    /**
     * Acquires a shared lock for ordinary bounded database work.
     * Returns null when maintenance is already active so callers can return/retry instead.
     */
    public function acquireShared(): ?DrMaintenanceLease
    {
        $this->ensureRoot();
        if ($this->isActive()) {
            return null;
        }

        $handle = $this->openLock();
        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new RuntimeException('Cannot acquire DR shared lock.');
        }

        // Close the race where restore created the marker while this process waited for LOCK_SH.
        if ($this->isActive()) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return null;
        }

        return new DrMaintenanceLease($handle);
    }

    /**
     * Publishes maintenance first, then waits for all shared holders to leave and takes LOCK_EX.
     * The returned lease owns both the exclusive lock and marker cleanup.
     *
     * @param array<string, scalar|null> $metadata
     */
    public function beginExclusive(array $metadata = []): DrMaintenanceLease
    {
        $this->ensureRoot();
        $handle = $this->openLock();
        $marker = $this->markerPath();
        $temporary = $marker . '.tmp-' . bin2hex(random_bytes(6));
        try {
            $payload = array_merge([
                'active' => true,
                'started_at' => gmdate('c'),
            ], $metadata);
            try {
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            } catch (JsonException $exception) {
                throw new RuntimeException('Cannot encode DR maintenance marker.', 0, $exception);
            }
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('Cannot write DR maintenance marker.');
            }
            @chmod($temporary, 0600);
            if (!rename($temporary, $marker)) {
                throw new RuntimeException('Cannot publish DR maintenance marker.');
            }
            @chmod($marker, 0600);

            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire DR exclusive lock.');
            }

            return new DrMaintenanceLease(
                $handle,
                static function () use ($marker): void {
                    @unlink($marker);
                }
            );
        } catch (Throwable $exception) {
            @unlink($temporary);
            @unlink($marker);
            fclose($handle);
            throw $exception;
        }
    }

    /** @return resource */
    private function openLock()
    {
        $handle = @fopen($this->lockPath(), 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open DR maintenance lock.');
        }
        @chmod($this->lockPath(), 0600);
        return $handle;
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root)) {
            if (!mkdir($this->root, 0700, true) && !is_dir($this->root)) {
                throw new RuntimeException('Cannot create DR state directory.');
            }
        }
        if (!is_writable($this->root)) {
            throw new RuntimeException('DR state directory is not writable.');
        }
        @chmod($this->root, 0700);
    }

    private function markerPath(): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'maintenance.json';
    }

    private function lockPath(): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'maintenance.lock';
    }
}

final class DrMaintenanceLease
{
    private bool $released = false;

    /** @param resource $handle */
    public function __construct(
        private $handle,
        private readonly ?\Closure $onRelease = null
    ) {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        try {
            if ($this->onRelease !== null) {
                ($this->onRelease)();
            }
        } finally {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
