<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Backup\BackupContainer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupContainerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mirvmon-backup-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testRoundTripPreservesFixedRecords(): void
    {
        $records = $this->records();
        $backup = $this->directory . '/backup.mmbak';
        $extracted = $this->directory . '/extracted';
        self::assertTrue(mkdir($extracted, 0700));

        $container = $this->container();
        $container->write($backup, 'correct horse battery staple', $records);
        $header = $container->extract($backup, 'correct horse battery staple', $extracted);

        self::assertSame(1, $header['format']);
        self::assertSame('argon2id13', $header['kdf']);
        foreach ($records as $name => $source) {
            self::assertFileExists($extracted . '/' . $name);
            self::assertSame(
                file_get_contents($source),
                file_get_contents($extracted . '/' . $name)
            );
        }
    }

    public function testWrongPasswordDoesNotLeaveExtractedRecords(): void
    {
        $backup = $this->directory . '/backup.mmbak';
        $extracted = $this->directory . '/wrong-password';
        self::assertTrue(mkdir($extracted, 0700));
        $container = $this->container();
        $container->write($backup, 'correct horse battery staple', $this->records());

        try {
            $container->extract($backup, 'incorrect password', $extracted);
            self::fail('Wrong password must reject the backup.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('password', strtolower($exception->getMessage()));
        }

        self::assertSame([], array_values(array_diff(scandir($extracted) ?: [], ['.', '..'])));
    }

    public function testTamperedPayloadFailsAuthenticationAndCleansPartialOutput(): void
    {
        $backup = $this->directory . '/backup.mmbak';
        $extracted = $this->directory . '/tampered';
        self::assertTrue(mkdir($extracted, 0700));
        $container = $this->container();
        $container->write($backup, 'correct horse battery staple', $this->records());

        $contents = file_get_contents($backup);
        self::assertIsString($contents);
        $offset = strlen($contents) - 24;
        $contents[$offset] = chr(ord($contents[$offset]) ^ 0x01);
        self::assertNotFalse(file_put_contents($backup, $contents));

        $this->expectException(RuntimeException::class);
        try {
            $container->extract($backup, 'correct horse battery staple', $extracted);
        } finally {
            self::assertSame([], array_values(array_diff(scandir($extracted) ?: [], ['.', '..'])));
        }
    }

    public function testRejectsUnexpectedRecordSet(): void
    {
        $container = $this->container();
        $this->expectException(RuntimeException::class);
        $container->write(
            $this->directory . '/backup.mmbak',
            'correct horse battery staple',
            ['manifest.json' => $this->writeFile('manifest-only', '{}')]
        );
    }

    /** @return array<string, string> */
    private function records(): array
    {
        return [
            'manifest.json' => $this->writeFile('manifest.json', '{"backup_type":"full"}'),
            'database.pgdump' => $this->writeFile(
                'database.pgdump',
                random_bytes(150000)
            ),
            'secrets.json' => $this->writeFile('secrets.json', '{"version":1,"secrets":[]}'),
        ];
    }

    private function writeFile(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        self::assertNotFalse(file_put_contents($path, $contents));
        return $path;
    }

    private function container(): BackupContainer
    {
        return new BackupContainer(
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );
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
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
