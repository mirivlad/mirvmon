<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\Migrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigratorTest extends TestCase
{
    public function testMigrationFilesAreReturnedInVersionOrder(): void
    {
        $directory = $this->createMigrationDirectory([
            '010_last.sql' => 'SELECT 10;',
            '002_second.sql' => 'SELECT 2;',
            '001_first.sql' => 'SELECT 1;',
        ]);

        self::assertSame(
            ['001_first.sql', '002_second.sql', '010_last.sql'],
            array_map('basename', Migrator::migrationFiles($directory))
        );
    }

    public function testInvalidMigrationFilenameIsRejected(): void
    {
        $directory = $this->createMigrationDirectory([
            '001_first.sql' => 'SELECT 1;',
            'manual.sql' => 'SELECT 2;',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid migration filename');

        Migrator::migrationFiles($directory);
    }

    public function testMissingMigrationDirectoryIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration directory');

        Migrator::migrationFiles(sys_get_temp_dir() . '/missing-mirvmon-migrations');
    }

    /**
     * @param array<string, string> $files
     */
    private function createMigrationDirectory(array $files): string
    {
        $directory = sys_get_temp_dir() . '/mirvmon-migrations-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));

        foreach ($files as $name => $contents) {
            self::assertNotFalse(file_put_contents($directory . '/' . $name, $contents));
        }

        return $directory;
    }
}
