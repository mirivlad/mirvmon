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
        self::assertStringNotContainsString('fleet.queue_note', $fleet);
        self::assertStringContainsString('text-nowrap', $fleet);
        self::assertStringContainsString('/api/agents/fleet-status', $fleet);
        self::assertStringContainsString('data-fleet-agent-row', $fleet);
        self::assertStringContainsString('window.setTimeout(poll, 5000)', $fleet);
    }

    public function testTopLevelPageHeadersMatchNavigationIconsAndStayConcise(): void
    {
        foreach ([
            'templates/dashboard.twig' => 'fa-table-cells-large',
            'templates/groups/index.twig' => 'fa-layer-group',
            'templates/servers/index.twig' => 'fa-server',
            'templates/agents/index.twig' => 'fa-microchip',
            'templates/sites/index.twig' => 'fa-globe',
            'templates/alerts/index.twig' => 'fa-triangle-exclamation',
            'templates/admin/users.twig' => 'fa-users',
            'templates/admin/notifications.twig' => 'fa-paper-plane',
            'templates/admin/notification-queue.twig' => 'fa-list-check',
            'templates/admin/audit.twig' => 'fa-clock-rotate-left',
            'templates/admin/system.twig' => 'fa-heart-pulse',
            'templates/admin/defaults.twig' => 'fa-sliders',
        ] as $path => $icon) {
            $template = $this->contents($path);
            self::assertStringContainsString($icon, $template, $path);
        }

        foreach ([
            'templates/dashboard.twig',
            'templates/groups/index.twig',
            'templates/servers/index.twig',
            'templates/agents/index.twig',
            'templates/sites/index.twig',
            'templates/admin/system.twig',
            'templates/admin/defaults.twig',
        ] as $path) {
            self::assertStringNotContainsString('page-eyebrow', $this->contents($path), $path);
        }

        $notifications = $this->contents('templates/admin/notifications.twig');
        self::assertStringNotContainsString('notifications.queue_hint', $notifications);
        self::assertLessThan(
            strpos($notifications, '<form method="post" action="/admin/notifications/save">'),
            strpos($notifications, 'notifications.open_queue')
        );
    }

    public function testOperationalPagesUseVisibilityAwareLiveFragments(): void
    {
        $app = $this->contents('public/js/app.js');
        self::assertStringContainsString('X-MirvMon-Live-Fragment', $app);
        self::assertStringContainsString("document.visibilityState !== 'visible'", $app);
        self::assertStringContainsString('liveFragmentHasEditableFocus(current)', $app);
        self::assertStringContainsString("active.matches('input, select, textarea, [contenteditable=\"true\"], [contenteditable=\"\"]')", $app);
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
