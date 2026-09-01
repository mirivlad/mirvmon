<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class Ui19TemplateContractTest extends TestCase
{
    public function testPrimaryNavigationExposesActiveRouteMarkers(): void
    {
        $layout = $this->contents('templates/layout.twig');

        self::assertStringContainsString('data-nav-exact="/"', $layout);
        self::assertStringContainsString('data-nav-prefix="/groups"', $layout);
        self::assertStringContainsString('data-nav-prefix="/servers"', $layout);
        self::assertStringContainsString('data-nav-prefix="/agents"', $layout);
        self::assertStringContainsString('data-nav-prefix="/alerts"', $layout);
        self::assertStringContainsString('data-nav-section="settings"', $layout);
        self::assertStringContainsString('/js/ui19.js', $layout);
    }

    public function testServerListUsesOperationalFiltersAndKeepsInlineAgentUpdates(): void
    {
        $template = $this->contents('templates/servers/index.twig');

        self::assertStringContainsString('data-server-search', $template);
        self::assertStringContainsString('data-server-group-filter', $template);
        self::assertStringContainsString('data-server-status-filter', $template);
        self::assertStringContainsString('data-agent-filter', $template);
        self::assertStringContainsString('advanced-server-filters', $template);
        self::assertStringContainsString('data-agent-update-button', $template);
        self::assertStringContainsString('data-agent-bulk-update-button', $template);
        self::assertStringContainsString('renderBulkProgress()', $template);
        self::assertStringContainsString('bulkOperationServerIds', $template);
        self::assertStringContainsString('agent.update_all_progress', $template);
        self::assertStringContainsString('/api/agent-updates/status', $template);

        $fleet = $this->contents('templates/agents/index.twig');
        self::assertStringContainsString('fleet-summary-grid', $fleet);
        self::assertStringContainsString('/servers/{{ server.id }}?tab=agent', $fleet);
        self::assertStringContainsString('fleet.queue_note', $fleet);
        self::assertStringContainsString('/api/agents/fleet-status', $fleet);
        self::assertStringContainsString('data-fleet-agent-row', $fleet);
        self::assertStringContainsString('window.setTimeout(poll, 5000)', $fleet);
    }

    public function testOperationalPagesUseVisibilityAwareLiveFragments(): void
    {
        $app = $this->contents('public/js/app.js');
        self::assertStringContainsString('X-MirvMon-Live-Fragment', $app);
        self::assertStringContainsString("document.visibilityState !== 'visible'", $app);
        self::assertStringContainsString('current.contains(document.activeElement)', $app);
        self::assertStringContainsString('mirvmon:live-fragment-before-update', $app);
        self::assertStringContainsString('cancelable: true', $app);
        self::assertStringContainsString('mirvmon:live-fragment-updated', $app);

        foreach ([
            'templates/dashboard.twig' => 'dashboard-attention',
            'templates/servers/index.twig' => 'server-health-',
            'templates/servers/detail.twig' => 'server-overview',
            'templates/servers/partials/detail-metrics.twig' => 'server-metrics',
            'templates/groups/index.twig' => 'group-overview',
            'templates/groups/show.twig' => 'group-operational',
            'templates/sites/index.twig' => 'website-list',
            'templates/sites/detail.twig' => 'website-overview',
            'templates/alerts/index.twig' => 'active-incidents',
            'templates/admin/system.twig' => 'system-details',
            'templates/admin/notification-queue.twig' => 'notification-queue',
        ] as $path => $fragment) {
            self::assertStringContainsString('data-live-fragment', $this->contents($path), $path);
            self::assertStringContainsString($fragment, $this->contents($path), $path);
        }

        $siteDetail = $this->contents('public/js/site-detail.js');
        self::assertStringContainsString('scheduleRefresh', $siteDetail);
        self::assertStringContainsString('30000', $siteDetail);
    }

    public function testGroupPagesUseMiniDashboardAndResponsiveListContracts(): void
    {
        $index = $this->contents('templates/groups/index.twig');
        $show = $this->contents('templates/groups/show.twig');
        $css = $this->contents('public/css/ui19.css');

        self::assertStringContainsString('group-dashboard-grid', $index);
        self::assertStringContainsString('group-status-strip', $index);
        self::assertStringContainsString('group-summary-grid', $show);
        self::assertStringContainsString('@media (max-width: 767.98px)', $css);
        self::assertStringContainsString('.server-list-table thead', $css);
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents);
        return $contents;
    }
}
