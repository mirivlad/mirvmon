<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Backup\DrMaintenanceLock;
use PHPUnit\Framework\TestCase;

final class DrMaintenanceLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mirvmon-dr-lock-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (['maintenance.json', 'maintenance.lock'] as $file) {
            @unlink($this->directory . '/' . $file);
        }
        @rmdir($this->directory);
    }

    public function testSharedWorkIsRejectedWhileMaintenanceMarkerIsActive(): void
    {
        $lock = new DrMaintenanceLock($this->directory);
        $shared = $lock->acquireShared();
        self::assertNotNull($shared);
        $shared->release();

        $exclusive = $lock->beginExclusive(['operation_id' => 'restore-test']);
        self::assertTrue($lock->isActive());
        self::assertNull($lock->acquireShared());
        self::assertFileExists($this->directory . '/maintenance.json');

        $exclusive->release();
        self::assertFalse($lock->isActive());
        self::assertNotNull($lock->acquireShared())?->release();
    }

    public function testExclusiveReleaseIsIdempotent(): void
    {
        $lock = new DrMaintenanceLock($this->directory);
        $exclusive = $lock->beginExclusive();
        $exclusive->release();
        $exclusive->release();

        self::assertFalse($lock->isActive());
    }
}
