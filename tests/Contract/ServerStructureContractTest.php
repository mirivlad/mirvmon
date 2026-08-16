<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class ServerStructureContractTest extends TestCase
{
    public function testServerPageUsesOperationalTabsAndNoConfigurationTabs(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/detail.twig'
        );

        foreach (['overview', 'metrics', 'services', 'events', 'agent'] as $tab) {
            self::assertStringContainsString('?tab={{ tab }}', $template);
            self::assertStringContainsString("server18.tabs." . $tab, $template);
        }
        self::assertStringNotContainsString("server.tabs.thresholds", $template);
        self::assertStringContainsString('servers/partials/detail-overview.twig', $template);
        self::assertStringContainsString('servers/partials/detail-events.twig', $template);
    }

    public function testOverviewSeparatesAgentContactFromSampleTimeAndShowsTimingDiagnosis(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/partials/detail-overview.twig'
        );

        self::assertStringContainsString('server.last_contact_at', $template);
        self::assertStringContainsString('server.last_metrics_at', $template);
        self::assertStringContainsString("timing.state == 'stale'", $template);
        self::assertStringContainsString("timing.state == 'ahead'", $template);
        self::assertStringContainsString('host_architecture', $template);
        self::assertStringContainsString('active_problem_count', $template);
    }

    public function testServiceObservationContainsNoMonitoringForm(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/partials/detail-services.twig'
        );

        self::assertStringNotContainsString('name="services[]"', $template);
        self::assertStringNotContainsString('action="/servers/{{ server.id }}/services"', $template);
        self::assertStringContainsString('/edit#services-monitoring', $template);
        self::assertStringContainsString('service.status', $template);
    }

    public function testServerSettingsCollectAllMonitoringConfiguration(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/edit.twig'
        );

        foreach ([
            'name="display_widgets[]"',
            'name="offline_timeout"',
            'name="notification_telegram_chat_id"',
            'name="notification_emails"',
            'action="/servers/{{ server.id }}/thresholds"',
            'action="/servers/{{ server.id }}/services"',
            'name="return_to" value="edit"',
            'name="services[]"',
        ] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
    }

    public function testMaintenanceIsAnExplicitActionUntilItIsActive(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/detail.twig'
        );

        self::assertStringContainsString('data-bs-target="#maintenanceModal"', $template);
        self::assertStringContainsString('{% if maintenance %}', $template);
        self::assertStringContainsString('{% if not maintenance %}', $template);
        self::assertStringContainsString('id="maintenanceModal"', $template);
    }

    public function testServerEventsAreScopedThroughIncidentRepository(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Controllers/ServerDetailController.php'
        );
        $events = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/partials/detail-events.twig'
        );

        self::assertStringContainsString("->active(['server_id' => \$serverId])", $controller);
        self::assertStringContainsString("->history(['server_id' => \$serverId])", $controller);
        self::assertStringContainsString('/alerts?view=history&server_id={{ server.id }}', $events);
        self::assertStringContainsString('/alerts?view=active&server_id={{ server.id }}', $events);
    }
}
