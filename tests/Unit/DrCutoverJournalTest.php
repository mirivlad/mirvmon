<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Backup\DrCutoverJournal;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DrCutoverJournalTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mirvmon-cutover-journal-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/cutover.json');
        @rmdir($this->root);
    }

    public function testJournalPersistsCutoverIdentityAndMonotonicPhases(): void
    {
        $journal = new DrCutoverJournal($this->root);
        $journal->begin(
            '0123456789abcdef0123456789abcdef',
            '11111111-1111-4111-8111-111111111111',
            'mirvmon',
            'mirvmon_restore_11111111',
            'mirvmon_pre_restore_11111111',
            ['database' => 'mirvmon']
        );

        $prepared = $journal->read();
        self::assertIsArray($prepared);
        self::assertSame('prepared', $prepared['phase']);
        self::assertSame('0123456789abcdef0123456789abcdef', $prepared['operation_id']);

        $journal->advance('current_renamed');
        $journal->advance('staging_renamed');
        $journal->advance('verified');
        $journal->advance('committed');
        self::assertSame('committed', $journal->read()['phase'] ?? null);

        $journal->clear();
        self::assertNull($journal->read());
    }

    public function testJournalCannotMoveCutoverPhaseBackwards(): void
    {
        $journal = new DrCutoverJournal($this->root);
        $journal->begin(
            'fedcba9876543210fedcba9876543210',
            '22222222-2222-4222-8222-222222222222',
            'mirvmon',
            'mirvmon_restore_22222222',
            'mirvmon_pre_restore_22222222',
            []
        );
        $journal->advance('verified');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot move backwards');
        $journal->advance('current_renamed');
    }
}
