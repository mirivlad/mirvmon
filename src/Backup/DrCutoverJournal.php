<?php

declare(strict_types=1);

namespace App\Backup;

use JsonException;
use RuntimeException;

final class DrCutoverJournal
{
    private const PHASES = [
        'prepared',
        'current_renamed',
        'staging_renamed',
        'verified',
        'committed',
    ];

    public function __construct(private readonly string $root)
    {
    }

    /** @param array<string, mixed> $result */
    public function begin(
        string $operationId,
        string $backupId,
        string $currentDatabase,
        string $stagingDatabase,
        string $previousDatabase,
        array $result
    ): void {
        if ($this->read() !== null) {
            throw new RuntimeException('A disaster-recovery cutover journal already exists.');
        }
        $this->validateOperationId($operationId);
        $this->validateBackupId($backupId);
        foreach ([$currentDatabase, $stagingDatabase, $previousDatabase] as $database) {
            $this->validateDatabaseName($database);
        }
        $this->write([
            'version' => 1,
            'operation_id' => $operationId,
            'backup_id' => $backupId,
            'phase' => 'prepared',
            'current_database' => $currentDatabase,
            'staging_database' => $stagingDatabase,
            'previous_database' => $previousDatabase,
            'result' => $result,
            'updated_at' => gmdate('c'),
        ]);
    }

    public function advance(string $phase): void
    {
        if (!in_array($phase, self::PHASES, true)) {
            throw new RuntimeException('Invalid disaster-recovery cutover phase.');
        }
        $journal = $this->read();
        if ($journal === null) {
            throw new RuntimeException('Disaster-recovery cutover journal is missing.');
        }
        $currentIndex = array_search((string) $journal['phase'], self::PHASES, true);
        $nextIndex = array_search($phase, self::PHASES, true);
        if (!is_int($currentIndex) || !is_int($nextIndex) || $nextIndex < $currentIndex) {
            throw new RuntimeException('Disaster-recovery cutover phase cannot move backwards.');
        }
        $journal['phase'] = $phase;
        $journal['updated_at'] = gmdate('c');
        $this->write($journal);
    }

    /**
     * @return array{
     *   version:int,
     *   operation_id:string,
     *   backup_id:string,
     *   phase:string,
     *   current_database:string,
     *   staging_database:string,
     *   previous_database:string,
     *   result:array<string,mixed>,
     *   updated_at:string
     * }|null
     */
    public function read(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Cannot read disaster-recovery cutover journal.');
        }
        try {
            $journal = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Disaster-recovery cutover journal is corrupt.', 0, $exception);
        }
        if (!is_array($journal)
            || ($journal['version'] ?? null) !== 1
            || !is_string($journal['operation_id'] ?? null)
            || !is_string($journal['backup_id'] ?? null)
            || !is_string($journal['phase'] ?? null)
            || !is_string($journal['current_database'] ?? null)
            || !is_string($journal['staging_database'] ?? null)
            || !is_string($journal['previous_database'] ?? null)
            || !is_array($journal['result'] ?? null)
            || !is_string($journal['updated_at'] ?? null)) {
            throw new RuntimeException('Disaster-recovery cutover journal has invalid structure.');
        }
        $this->validateOperationId($journal['operation_id']);
        $this->validateBackupId($journal['backup_id']);
        if (!in_array($journal['phase'], self::PHASES, true)) {
            throw new RuntimeException('Disaster-recovery cutover journal has an invalid phase.');
        }
        foreach (['current_database', 'staging_database', 'previous_database'] as $field) {
            $this->validateDatabaseName($journal[$field]);
        }

        /** @var array{version:int,operation_id:string,backup_id:string,phase:string,current_database:string,staging_database:string,previous_database:string,result:array<string,mixed>,updated_at:string} $journal */
        return $journal;
    }

    public function clear(): void
    {
        $path = $this->path();
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Cannot remove disaster-recovery cutover journal.');
        }
    }

    /** @param array<string, mixed> $journal */
    private function write(array $journal): void
    {
        $this->ensureRoot();
        try {
            $json = json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode disaster-recovery cutover journal.', 0, $exception);
        }
        $path = $this->path();
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $written = file_put_contents($temporary, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write disaster-recovery cutover journal.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot publish disaster-recovery cutover journal.');
        }
        @chmod($path, 0600);
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root)
            && !mkdir($this->root, 0700, true)
            && !is_dir($this->root)) {
            throw new RuntimeException('Cannot create disaster-recovery state directory.');
        }
        if (!is_writable($this->root)) {
            throw new RuntimeException('Disaster-recovery state directory is not writable.');
        }
        @chmod($this->root, 0700);
    }

    private function path(): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cutover.json';
    }

    private function validateOperationId(string $operationId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $operationId) !== 1) {
            throw new RuntimeException('Invalid disaster-recovery operation ID.');
        }
    }

    private function validateBackupId(string $backupId): void
    {
        if (preg_match('/^[a-f0-9-]{36}$/', $backupId) !== 1) {
            throw new RuntimeException('Invalid disaster-recovery backup ID.');
        }
    }

    private function validateDatabaseName(string $database): void
    {
        if ($database === '' || strlen($database) > 63 || str_contains($database, "\0")) {
            throw new RuntimeException('Invalid database name in disaster-recovery cutover journal.');
        }
    }
}
