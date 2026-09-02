<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Backup\BackupOperationStore;
use App\Security\SecretCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupOperationStoreTest extends TestCase
{
    private string $root;
    private BackupOperationStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mirvmon-backup-store-' . bin2hex(random_bytes(6));
        $this->store = new BackupOperationStore($this->root, new SecretCipher(str_repeat('k', 32)));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPasswordHandoffIsEncryptedAndHiddenFromPublicState(): void
    {
        $created = $this->store->begin('correct horse battery staple', 'mirvmon-full-test.mmbak');
        self::assertSame('queued', $created['status']);
        self::assertArrayNotHasKey('password_handoff', $created);

        $raw = (string) file_get_contents($this->root . '/' . $created['id'] . '/state.json');
        self::assertStringNotContainsString('correct horse battery staple', $raw);
        self::assertStringContainsString('password_handoff', $raw);

        $claimed = $this->store->claimNext('worker:test');
        self::assertIsArray($claimed);
        self::assertSame('correct horse battery staple', $this->store->passwordForClaim($claimed));
        self::assertArrayNotHasKey('password_handoff', $this->store->operation($created['id']));
    }

    public function testInterruptedBackupIsRequeuedWithSameEncryptedHandoff(): void
    {
        $created = $this->store->begin('restart-safe-password', 'mirvmon-full-test.mmbak');
        $claimed = $this->store->claimNext('worker:first');
        self::assertIsArray($claimed);
        file_put_contents($this->store->outputPath($created['id']), 'partial-or-complete-old-output');
        mkdir($this->store->workspaceRoot($created['id']), 0700, true);
        file_put_contents($this->store->workspaceRoot($created['id']) . '/plaintext-secret', 'must-disappear');

        $this->store->requeueInterrupted();
        self::assertSame('queued', $this->store->operation($created['id'])['status']);
        self::assertFileDoesNotExist($this->store->outputPath($created['id']));
        self::assertDirectoryDoesNotExist($this->store->workspaceRoot($created['id']));

        $claimedAgain = $this->store->claimNext('worker:second');
        self::assertIsArray($claimedAgain);
        self::assertSame('restart-safe-password', $this->store->passwordForClaim($claimedAgain));
    }

    public function testSuccessfulBackupDropsPasswordHandoffAndBecomesDownloadable(): void
    {
        $created = $this->store->begin('download-password', 'mirvmon-full-0.6.0-test.mmbak');
        $claimed = $this->store->claimNext('worker:test');
        self::assertIsArray($claimed);
        file_put_contents($this->store->outputPath($created['id']), 'encrypted-backup');
        $this->store->markSucceeded($created['id'], ['backup_id' => '11111111-1111-4111-8111-111111111111']);

        $operation = $this->store->operation($created['id']);
        self::assertSame('succeeded', $operation['status']);
        self::assertSame(strlen('encrypted-backup'), $operation['size']);
        self::assertArrayNotHasKey('password_handoff', $operation);
        $download = $this->store->download($created['id']);
        self::assertSame('mirvmon-full-0.6.0-test.mmbak', $download['filename']);
        self::assertSame($this->store->outputPath($created['id']), $download['path']);

        $raw = (string) file_get_contents($this->root . '/' . $created['id'] . '/state.json');
        self::assertStringNotContainsString('password_handoff', $raw);
    }

    public function testFailedBackupDeletesOutputAndPasswordHandoff(): void
    {
        $created = $this->store->begin('failure-password', 'mirvmon-full-test.mmbak');
        self::assertIsArray($this->store->claimNext('worker:test'));
        file_put_contents($this->store->outputPath($created['id']), 'bad-output');
        $this->store->markFailed($created['id'], 'backup_failed');

        self::assertSame('failed', $this->store->operation($created['id'])['status']);
        self::assertFileDoesNotExist($this->store->outputPath($created['id']));
        $raw = (string) file_get_contents($this->root . '/' . $created['id'] . '/state.json');
        self::assertStringNotContainsString('password_handoff', $raw);

        $this->expectException(RuntimeException::class);
        $this->store->download($created['id']);
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
