<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class DomainDataSnapshotContractTest extends TestCase
{
    public function testSnapshotsHaveOfficialSourcesAndMatchingHashes(): void
    {
        $directory = dirname(__DIR__, 2) . '/resources/domain';
        $metadata = json_decode((string) file_get_contents($directory . '/snapshot-metadata.json'), true);

        self::assertIsArray($metadata);
        self::assertSame('https://data.iana.org/rdap/dns.json', $metadata['iana_rdap_bootstrap']['source'] ?? null);
        self::assertSame('https://publicsuffix.org/list/public_suffix_list.dat', $metadata['public_suffix_list']['source'] ?? null);
        self::assertSame(
            $metadata['iana_rdap_bootstrap']['sha256'] ?? null,
            hash_file('sha256', $directory . '/iana-rdap-bootstrap.json'),
        );
        self::assertSame(
            $metadata['public_suffix_list']['sha256'] ?? null,
            hash_file('sha256', $directory . '/public_suffix_list.dat'),
        );
    }

    public function testRefreshCommandStagesAndValidatesBeforeReplacingSnapshots(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/refresh-domain-data');

        self::assertStringContainsString('tempnam(', $script);
        self::assertStringContainsString('validateBootstrap(', $script);
        self::assertStringContainsString('validatePsl(', $script);
        self::assertStringContainsString('rename(', $script);
        self::assertStringNotContainsString('file_get_contents($source', $script);
    }
}
