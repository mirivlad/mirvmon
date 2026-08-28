<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Services\TlsCertificateInspector;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebsiteTlsFixture;

final class TlsCertificateInspectorTest extends TestCase
{
    private WebsiteTlsFixture $fixtures;
    private TlsCertificateInspector $inspector;
    private int $selfSignedPort;
    private int $wrongHostPort;
    private int $expiredPort;

    protected function setUp(): void
    {
        $this->fixtures = new WebsiteTlsFixture();
        $certificates = dirname(__DIR__, 2) . '/Fixtures/Websites/certs/';
        $this->selfSignedPort = $this->fixtures->start($certificates . 'valid-self-signed.pem');
        $this->wrongHostPort = $this->fixtures->start($certificates . 'wrong-host.pem');
        $this->expiredPort = $this->fixtures->start($certificates . 'expired.pem');
        $this->inspector = new TlsCertificateInspector();
    }

    protected function tearDown(): void
    {
        $this->fixtures->stop();
    }

    public function testSelfSignedModeAcceptsOnlyUnknownCaForConfiguredHost(): void
    {
        $now = new DateTimeImmutable('2026-08-28T00:00:00Z');
        $strict = $this->inspector->inspect(3, 10, 'localhost', $this->selfSignedPort, false, $now);
        $allowed = $this->inspector->inspect(3, 10, 'localhost', $this->selfSignedPort, true, $now);

        self::assertSame('untrusted_chain', $strict->errorKind);
        self::assertTrue($allowed->valid, $allowed->errorKind ?? 'missing error kind');
        self::assertSame('localhost', $allowed->hostname);
        self::assertNotNull($allowed->fingerprintSha256);
        self::assertSame(64, strlen((string) $allowed->fingerprintSha256));
    }

    public function testSelfSignedModeStillRejectsWrongHostname(): void
    {
        $result = $this->inspector->inspect(
            1,
            10,
            'localhost',
            $this->wrongHostPort,
            true,
            new DateTimeImmutable('2026-08-28T00:00:00Z'),
        );

        self::assertFalse($result->valid);
        self::assertSame('hostname_mismatch', $result->errorKind);
    }

    public function testSelfSignedModeStillRejectsExpiredCertificate(): void
    {
        $result = $this->inspector->inspect(
            2,
            10,
            'localhost',
            $this->expiredPort,
            true,
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
        );

        self::assertFalse($result->valid);
        self::assertSame('certificate_expired', $result->errorKind);
    }

    public function testExpiredFixtureIsExpiredAtTheFixedInspectionTime(): void
    {
        $certificate = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Websites/certs/expired.pem');
        $parsed = $certificate === false ? false : openssl_x509_parse($certificate, false);

        self::assertIsArray($parsed);
        self::assertLessThan(
            (new DateTimeImmutable('2030-01-01T00:00:00Z'))->getTimestamp(),
            (int) ($parsed['validTo_time_t'] ?? 0),
        );
    }
}
