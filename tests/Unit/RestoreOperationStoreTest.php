<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Backup\RestoreOperationStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RestoreOperationStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mirvmon-restore-store-' . bin2hex(random_bytes(6)) . '/operations';
    }

    protected function tearDown(): void
    {
        $this->removeTree(dirname($this->root));
    }

    public function testSequentialChunksFinalizeUploadedBackup(): void
    {
        $store = new RestoreOperationStore($this->root, 4096);
        $started = $store->begin('backup.mmbak', 9);
        $first = $this->chunk('abcd');
        $second = $this->chunk('efghi');
        try {
            $progress = $store->append($started['id'], 0, $first);
            self::assertFalse($progress['complete']);
            self::assertSame(4, $progress['received_bytes']);

            $progress = $store->append($started['id'], 1, $second);
            self::assertTrue($progress['complete']);
            self::assertSame(9, $progress['received_bytes']);

            $uploaded = $store->uploaded($started['id']);
            self::assertSame('uploaded', $uploaded['status']);
            self::assertSame('abcdefghi', file_get_contents((string) $uploaded['backup_path']));
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    public function testOutOfSequenceChunkDoesNotChangeProgress(): void
    {
        $store = new RestoreOperationStore($this->root, 4096);
        $started = $store->begin('backup.mmbak', 4);
        $chunk = $this->chunk('abcd');
        try {
            try {
                $store->append($started['id'], 1, $chunk);
                self::fail('Out-of-sequence upload must fail.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('out of sequence', $exception->getMessage());
            }
            $progress = $store->append($started['id'], 0, $chunk);
            self::assertTrue($progress['complete']);
        } finally {
            @unlink($chunk);
        }
    }

    public function testReadyOperationRequiresExistingPreflightWorkspace(): void
    {
        $store = new RestoreOperationStore($this->root, 4096);
        $started = $store->begin('backup.mmbak', 4);
        $chunk = $this->chunk('abcd');
        try {
            $store->append($started['id'], 0, $chunk);
            $workspace = $store->workspacePath($started['id']);
            mkdir($workspace, 0700, true);
            $store->markReady(
                $started['id'],
                ['backup_id' => '11111111-1111-4111-8111-111111111111'],
                ['version mismatch'],
                $workspace
            );

            $ready = $store->ready($started['id']);
            self::assertSame('ready', $ready['status']);
            self::assertSame(['version mismatch'], $ready['warnings']);
        } finally {
            @unlink($chunk);
        }
    }

    public function testQueuedOperationIsClaimedAndRequeuedAfterWorkerRestart(): void
    {
        $store = new RestoreOperationStore($this->root, 4096);
        $started = $store->begin('backup.mmbak', 4);
        $chunk = $this->chunk('abcd');
        try {
            $store->append($started['id'], 0, $chunk);
            $workspace = $store->workspacePath($started['id']);
            mkdir($workspace, 0700, true);
            $store->markReady(
                $started['id'],
                ['backup_id' => '22222222-2222-4222-8222-222222222222'],
                [],
                $workspace
            );
            $store->queue($started['id']);

            $claimed = $store->claimNext('test-worker:1');
            self::assertIsArray($claimed);
            self::assertSame($started['id'], $claimed['id']);
            self::assertSame('running', $claimed['status']);
            self::assertSame('test-worker:1', $claimed['worker_id']);

            $store->requeueInterrupted();
            $requeued = $store->operation($started['id']);
            self::assertSame('queued', $requeued['status']);
            self::assertTrue($requeued['recovered_after_worker_restart']);

            $claimedAgain = $store->claimNext('test-worker:2');
            self::assertIsArray($claimedAgain);
            self::assertSame('running', $claimedAgain['status']);
            self::assertSame('test-worker:2', $claimedAgain['worker_id']);
        } finally {
            @unlink($chunk);
        }
    }

    public function testSuccessfulWorkerCompletionRemovesPlaintextRestorePayload(): void
    {
        $store = new RestoreOperationStore($this->root, 4096);
        $started = $store->begin('backup.mmbak', 4);
        $chunk = $this->chunk('abcd');
        try {
            $store->append($started['id'], 0, $chunk);
            $workspace = $store->workspacePath($started['id']);
            mkdir($workspace, 0700, true);
            file_put_contents($workspace . '/secrets.json', '{"secret":"plaintext"}');
            $store->markReady(
                $started['id'],
                ['backup_id' => '33333333-3333-4333-8333-333333333333'],
                [],
                $workspace
            );
            $store->queue($started['id']);
            self::assertIsArray($store->claimNext('test-worker'));

            $store->markSucceeded($started['id'], ['database' => 'mirvmon']);

            $completed = $store->operation($started['id']);
            self::assertSame('succeeded', $completed['status']);
            self::assertSame(['database' => 'mirvmon'], $completed['result']);
            self::assertDirectoryDoesNotExist($workspace);
        } finally {
            @unlink($chunk);
        }
    }

    private function chunk(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mirvmon-restore-chunk-');
        self::assertNotFalse($path);
        self::assertSame(strlen($contents), file_put_contents($path, $contents));
        return $path;
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
