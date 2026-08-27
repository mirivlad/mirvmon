<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Websites;

use App\Domain\Websites\ExpectedStatusSet;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpectedStatusSetTest extends TestCase
{
    public function testParsesCodesAndRangesIntoCanonicalIntervals(): void
    {
        $set = ExpectedStatusSet::fromString('200, 201-204, 401,403');

        self::assertSame([
            ['min' => 200, 'max' => 200],
            ['min' => 201, 'max' => 204],
            ['min' => 401, 'max' => 401],
            ['min' => 403, 'max' => 403],
        ], $set->ranges());
        self::assertTrue($set->accepts(204));
        self::assertTrue($set->accepts(401));
        self::assertFalse($set->accepts(302));
    }

    #[DataProvider('invalidExpressions')]
    public function testRejectsMalformedOrOutOfRangeStatuses(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExpectedStatusSet::fromString($expression);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidExpressions(): iterable
    {
        yield 'empty' => [''];
        yield 'below HTTP range' => ['99'];
        yield 'above HTTP range' => ['700'];
        yield 'reversed range' => ['204-200'];
        yield 'missing range end' => ['200-'];
        yield 'non numeric' => ['ok'];
        yield 'empty item' => ['200,,204'];
    }
}
