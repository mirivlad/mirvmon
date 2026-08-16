<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class IncidentUiContractTest extends TestCase
{
    public function testIncidentPageKeepsOperationalViewsFiltersAndLinks(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/alerts/index.twig'
        );

        foreach ([
            '/alerts?view=active',
            '/alerts?view=history',
            'name="server_id"',
            'name="group_id"',
            'name="kind"',
            'name="severity"',
            'name="from"',
            'name="to"',
            '/servers/{{ event.server_id }}',
            '/groups/{{ event.group_id }}',
            'event.id is not null',
        ] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
    }

    public function testDashboardStatusCardsRemainIncidentAwareFilters(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/dashboard.twig'
        );
        foreach (['online', 'warning', 'critical', 'offline'] as $status) {
            self::assertStringContainsString('/?status=' . $status, $template);
        }
        self::assertStringContainsString("t('incidents.attention.title')", $template);
        self::assertStringContainsString('/alerts?view=active', $template);

        $script = (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/js/dashboard.js'
        );
        self::assertStringContainsString("searchParams.get('status')", $script);
        self::assertStringContainsString("searchParams.set('status', statusFilter.value)", $script);
        self::assertStringContainsString("searchParams.delete('status')", $script);
    }

    public function testPrimaryNavigationUsesIncidentTerminology(): void
    {
        $layout = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/layout.twig'
        );

        self::assertStringContainsString('href="/alerts"', $layout);
        self::assertStringContainsString("t('nav.incidents')", $layout);
    }
}
