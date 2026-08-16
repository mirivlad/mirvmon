<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class Ui19ContractTest extends TestCase
{
    public function testSharedNavigationAndSectionPolishAreLoaded(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/templates/layout.twig');
        $script = (string) file_get_contents($root . '/public/js/ui19.js');
        $reviewCss = (string) file_get_contents($root . '/public/css/ui19-review.css');

        foreach ([
            '/css/ui19.css',
            '/css/ui19-review.css',
            '/js/ui19.js',
            'data-nav-exact="/"',
            'data-nav-prefix="/groups"',
            'data-nav-prefix="/servers"',
            'data-nav-prefix="/alerts"',
            'data-nav-section="settings"',
        ] as $needle) {
            self::assertStringContainsString($needle, $layout);
        }

        foreach ([
            "ui-section-dashboard",
            "ui-section-incidents",
            "ui-section-system",
            "prepareResponsiveIncidentTables",
            "ui-responsive-table",
            "cell.dataset.label",
        ] as $needle) {
            self::assertStringContainsString($needle, $script);
        }

        self::assertStringContainsString('.ui-section-incidents .ui-responsive-table', $reviewCss);
        self::assertStringContainsString('@media (max-width: 767.98px)', $reviewCss);
        self::assertStringContainsString('.ui-section-system', $reviewCss);
        self::assertStringContainsString('.ui-section-dashboard', $reviewCss);
    }

    public function testServerListUsesPrimaryAndAdvancedOperationalFilters(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/index.twig'
        );

        foreach ([
            'data-server-search',
            'data-server-group-filter',
            'data-server-status-filter',
            'data-agent-filter',
            'advanced-server-filters',
            'data-agent-update-button',
            '/api/agent-updates/status?ids=',
        ] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
    }

    public function testGroupsExposeOperationalSummariesAndServerHealth(): void
    {
        $root = dirname(__DIR__, 2);
        $index = (string) file_get_contents($root . '/templates/groups/index.twig');
        $show = (string) file_get_contents($root . '/templates/groups/show.twig');

        foreach ([
            'group-dashboard-grid',
            'summary.active_problems',
            'summary.online',
            'summary.warning',
            'summary.critical',
            'summary.offline',
        ] as $needle) {
            self::assertStringContainsString($needle, $index);
        }

        foreach ([
            'group-summary-grid',
            'summary.active_problems',
            'server-list-table',
            "t('status.' ~ server.status)",
        ] as $needle) {
            self::assertStringContainsString($needle, $show);
        }
    }
}
