<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerTimingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerTimingServiceTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, string, ?int}> */
    public static function cases(): iterable
    {
        yield 'unknown without contact' => [null, '2026-08-16T10:00:00+00:00', 'unknown', null];
        yield 'unknown without sample' => ['2026-08-16T10:00:00+00:00', null, 'unknown', null];
        yield 'small lag is normal' => ['2026-08-16T10:00:00+00:00', '2026-08-16T09:57:00+00:00', 'ok', 180];
        yield 'boundary is normal' => ['2026-08-16T10:00:00+00:00', '2026-08-16T09:55:00+00:00', 'ok', 300];
        yield 'queued sample is stale' => ['2026-08-16T10:00:00+00:00', '2026-08-16T09:50:00+00:00', 'stale', 600];
        yield 'host clock ahead' => ['2026-08-16T10:00:00+00:00', '2026-08-16T10:10:00+00:00', 'ahead', 600];
    }

    #[DataProvider('cases')]
    public function testTimingState(
        ?string $contact,
        ?string $sample,
        string $state,
        ?int $absoluteSeconds
    ): void {
        $diagnostic = (new ServerTimingService())->diagnose($contact, $sample);

        self::assertSame($state, $diagnostic['state']);
        self::assertSame($absoluteSeconds, $diagnostic['absolute_seconds']);
    }
}
