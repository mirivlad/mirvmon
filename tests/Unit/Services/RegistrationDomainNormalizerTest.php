<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RegistrationDomainNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationDomainNormalizerTest extends TestCase
{
    #[DataProvider('domains')]
    public function testNormalizesOnlyRegistrableDomains(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer()->normalize($input));
    }

    public static function domains(): iterable
    {
        yield ['пример.рф', 'xn--e1afmkfd.xn--p1ai'];
        yield ['example.co.uk', 'example.co.uk'];
        yield ['corp.local', 'corp.local'];
        yield ['EXAMPLE.COM.', 'example.com'];
        yield ['city.kawasaki.jp', 'city.kawasaki.jp'];
    }

    #[DataProvider('invalidDomains')]
    public function testRejectsAnythingOtherThanTheRegistrableDomain(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->normalizer()->normalize($input);
    }

    public static function invalidDomains(): iterable
    {
        yield ['https://example.com'];
        yield ['example.com/path'];
        yield ['127.0.0.1'];
        yield ['www.example.com'];
        yield ['co.uk'];
        yield ['com'];
        yield ['api.corp.local'];
    }

    private function normalizer(): RegistrationDomainNormalizer
    {
        return new RegistrationDomainNormalizer(dirname(__DIR__, 2) . '/Fixtures/Websites/public_suffix_list.dat');
    }
}
