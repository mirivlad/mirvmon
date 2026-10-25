<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ConnectivityTransitionGuard;
use PHPUnit\Framework\TestCase;

final class ConnectivityTransitionGuardTest extends TestCase
{
    public function testIsolatedFailuresDoNotTriggerOfflineState(): void
    {
        $guard = new ConnectivityTransitionGuard('online');

        self::assertNull($guard->observe(false));
        self::assertNull($guard->observe(false));
        self::assertSame('online', $guard->observe(true));
        self::assertSame('online', $guard->confirmedState());
        self::assertNull($guard->observe(false)); // Reset the failure streak.
    }

    public function testThreeFailuresConfirmOfflineAndTwoSuccessesConfirmRecovery(): void
    {
        $guard = new ConnectivityTransitionGuard('online');

        self::assertNull($guard->observe(false));
        self::assertNull($guard->observe(false));
        self::assertSame('offline', $guard->observe(false));
        self::assertSame('offline', $guard->confirmedState());

        self::assertNull($guard->observe(true));
        self::assertSame('offline', $guard->observe(false));
        self::assertNull($guard->observe(true));
        self::assertSame('online', $guard->observe(true));
        self::assertSame('online', $guard->confirmedState());
    }

    public function testFreshWorkerRequiresConfirmationFromUnknownState(): void
    {
        $guard = new ConnectivityTransitionGuard();

        self::assertNull($guard->observe(true));
        self::assertSame('online', $guard->observe(true));
        self::assertSame('online', $guard->observe(true));
    }

    public function testUnknownStateRequiresThreeFailuresBeforeOffline(): void
    {
        $guard = new ConnectivityTransitionGuard();
        self::assertNull($guard->observe(false));
        self::assertNull($guard->observe(false));
        self::assertSame('offline', $guard->observe(false));
    }
}
