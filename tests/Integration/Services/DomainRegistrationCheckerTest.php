<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Services\DomainRegistrationChecker;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\DomainRdapFixture;
use Tests\Support\DomainWhoisFixture;

final class DomainRegistrationCheckerTest extends TestCase
{
    private DomainRdapFixture $fixture;
    private DomainRegistrationChecker $checker;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->fixture = new DomainRdapFixture();
        $this->fixture->start();
        $this->checker = new DomainRegistrationChecker(null, $this->fixture->bootstrapPath());
        $this->now = new DateTimeImmutable('2026-08-28T00:00:00Z');
    }

    protected function tearDown(): void
    {
        $this->fixture->stop();
    }

    public function testRdapUsesTheMostSpecificBootstrapZone(): void
    {
        $result = $this->checker->check('example.com', $this->now);

        self::assertSame('supported', $result->status);
        self::assertSame('rdap', $result->source);
        self::assertSame('Specific Registrar', $result->registrar);
        self::assertSame('2028-02-16T12:00:00+00:00', $result->expiresAt?->format(DATE_ATOM));
    }

    public function testRdapRateLimitIsBoundedAndDoesNotFallThroughToWhois(): void
    {
        $result = $this->checker->check('rate.com', $this->now);

        self::assertSame('unknown', $result->status);
        self::assertSame('rdap', $result->source);
        self::assertSame('rate_limited', $result->errorKind);
        self::assertSame(120, $result->retryAfterSeconds);
    }

    public function testMalformedRdapResponseIsSourceUnknown(): void
    {
        $result = $this->checker->check('malformed.com', $this->now);

        self::assertSame('unknown', $result->status);
        self::assertSame('rdap_malformed', $result->errorKind);
    }

    public function testInternalZoneIsExplicitlyUnsupported(): void
    {
        $result = $this->checker->check('corp.local', $this->now);

        self::assertSame('unsupported', $result->status);
        self::assertSame('unsupported_zone', $result->errorKind);
    }

    public function testWhoisFallbackRunsOnlyForAnExplicitProfile(): void
    {
        $whois = new DomainWhoisFixture();
        $whois->start();

        try {
            $checker = new DomainRegistrationChecker(null, $this->fixture->bootstrapPath(), $whois->profilesPath());
            $result = $checker->check('example.test', $this->now);

            self::assertSame('supported', $result->status);
            self::assertSame('whois', $result->source);
            self::assertSame('2027-03-04T05:06:07+00:00', $result->expiresAt?->format(DATE_ATOM));
        } finally {
            $whois->stop();
        }
    }

    public function testWhoisFollowsOneReferral(): void
    {
        $whois = new DomainWhoisFixture();
        $whois->start();

        try {
            $checker = new DomainRegistrationChecker(null, $this->fixture->bootstrapPath(), $whois->profilesPath());
            $result = $checker->check('referral.test', $this->now);

            self::assertSame('supported', $result->status);
            self::assertSame('whois', $result->source);
            self::assertSame('2027-03-04T05:06:07+00:00', $result->expiresAt?->format(DATE_ATOM));
        } finally {
            $whois->stop();
        }
    }
}
