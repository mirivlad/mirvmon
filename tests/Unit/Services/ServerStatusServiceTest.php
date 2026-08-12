<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerStatusService;
use App\Services\ServerPlatformService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerStatusServiceTest extends TestCase
{
    private ServerStatusService $service;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->service = new ServerStatusService(new ServerPlatformService());
        $this->now = new DateTimeImmutable('2026-07-30T12:00:00+00:00');
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('statusCases')]
    public function testStatusUsesPerServerTimeoutAndAlertSeverity(
        array $server,
        string $expected
    ): void {
        self::assertSame($expected, $this->service->status($server, $this->now));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function statusCases(): iterable
    {
        yield 'no samples' => [[
            'is_active' => true,
            'last_metrics_at' => null,
            'offline_timeout_seconds' => 300,
            'warning_alerts' => 0,
            'critical_alerts' => 0,
        ], 'offline'];

        yield 'stale for short timeout' => [[
            'is_active' => true,
            'last_metrics_at' => '2026-07-30T11:57:59+00:00',
            'offline_timeout_seconds' => 120,
            'warning_alerts' => 0,
            'critical_alerts' => 0,
        ], 'offline'];

        yield 'fresh for long timeout' => [[
            'is_active' => true,
            'last_metrics_at' => '2026-07-30T11:57:59+00:00',
            'offline_timeout_seconds' => 600,
            'warning_alerts' => 0,
            'critical_alerts' => 0,
        ], 'online'];

        yield 'zero disables stale timeout' => [[
            'is_active' => true,
            'last_metrics_at' => '2026-07-01T00:00:00+00:00',
            'offline_timeout_seconds' => 0,
            'warning_alerts' => 0,
            'critical_alerts' => 0,
        ], 'online'];

        yield 'warning alert' => [[
            'is_active' => true,
            'last_metrics_at' => '2026-07-30T11:59:00+00:00',
            'offline_timeout_seconds' => 300,
            'warning_alerts' => 1,
            'critical_alerts' => 0,
        ], 'warning'];

        yield 'critical takes priority' => [[
            'is_active' => true,
            'last_metrics_at' => '2026-07-30T11:59:00+00:00',
            'offline_timeout_seconds' => 300,
            'warning_alerts' => 2,
            'critical_alerts' => 1,
        ], 'critical'];

        yield 'offline takes priority over alerts' => [[
            'is_active' => true,
            'last_metrics_at' => '2026-07-30T11:00:00+00:00',
            'offline_timeout_seconds' => 300,
            'warning_alerts' => 0,
            'critical_alerts' => 1,
        ], 'offline'];

        yield 'inactive server is offline' => [[
            'is_active' => false,
            'last_metrics_at' => '2026-07-30T11:59:59+00:00',
            'offline_timeout_seconds' => 300,
            'warning_alerts' => 0,
            'critical_alerts' => 0,
        ], 'offline'];
    }

    public function testSummaryUsesTheSameEnrichedStatusesAsCards(): void
    {
        $servers = $this->service->enrich([
            [
                'is_active' => true,
                'last_metrics_at' => '2026-07-30T11:59:00+00:00',
                'offline_timeout_seconds' => 300,
                'active_alerts' => 0,
                'warning_alerts' => 0,
                'critical_alerts' => 0,
            ],
            [
                'is_active' => true,
                'last_metrics_at' => '2026-07-30T11:59:00+00:00',
                'offline_timeout_seconds' => 300,
                'active_alerts' => 2,
                'warning_alerts' => 1,
                'critical_alerts' => 1,
            ],
            [
                'is_active' => true,
                'last_metrics_at' => '2026-07-30T10:00:00+00:00',
                'offline_timeout_seconds' => 300,
                'active_alerts' => 1,
                'warning_alerts' => 1,
                'critical_alerts' => 0,
            ],
        ], $this->now);

        self::assertSame(
            ['online', 'critical', 'offline'],
            array_column($servers, 'status')
        );
        self::assertSame([
            'total_servers' => 3,
            'servers_with_metrics' => 2,
            'total_groups' => 4,
            'alerts_count' => 3,
            'warnings' => 2,
            'criticals' => 1,
            'online_servers' => 1,
            'warning_servers' => 0,
            'critical_servers' => 1,
            'offline_servers' => 1,
        ], $this->service->summary($servers, 4));
    }

    public function testEnrichAddsPlatformWithoutReplacingStatus(): void
    {
        $server = $this->service->enrich([[
            'is_active' => true,
            'last_metrics_at' => '2026-07-30T11:59:00+00:00',
            'offline_timeout_seconds' => 300,
            'warning_alerts' => 1,
            'critical_alerts' => 0,
            'os_version' => 'Oracle Linux Server 9',
            'agent_artifact' => null,
        ]], $this->now)[0];

        self::assertSame('warning', $server['status']);
        self::assertSame('linux', $server['platform']['family']);
        self::assertSame('fab fa-linux', $server['platform']['icon_class']);
    }
}
