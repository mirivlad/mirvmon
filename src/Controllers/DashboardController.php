<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\IncidentRepository;
use App\Repositories\ServerRepository;
use App\Services\ServerStatusService;
use App\Services\SystemHealthService;
use DateTimeImmutable;
use JsonException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly ServerRepository $servers,
        private readonly ServerStatusService $status,
        private readonly Translator $translator = new Translator(),
        private readonly ?SystemHealthService $systemHealth = null,
        private readonly ?IncidentRepository $incidents = null
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $servers = $this->status->enrich($this->servers->dashboardServers());
        $groups = [];

        foreach ($servers as $server) {
            $groupKey = $server['group_id'] === null
                ? 'ungrouped'
                : 'group-' . $server['group_id'];

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'name' => $server['group_name'] ?: $this->translator->trans('dashboard.ungrouped'),
                    'color' => $server['group_color'] ?: '#6c757d',
                    'icon' => $server['group_icon'] ?: 'fa-server',
                    'servers' => [],
                ];
            }
            $groups[$groupKey]['servers'][] = $server;
        }

        return $this->twig->render($response, 'dashboard.twig', [
            'title' => $this->translator->trans('dashboard.title'),
            'stats' => $this->status->summary($servers, $this->servers->groupCount()),
            'groups' => $groups,
            'attention' => $this->incidents?->attention() ?? $this->fallbackAttention($servers),
            'system_health' => $this->systemHealth?->summary() ?? [
                'application_status' => 'unknown',
                'host_status' => 'unknown',
                'host_configured' => false,
            ],
        ]);
    }

    /**
     * @param array<string, string> $args
     * @throws JsonException
     */
    public function getDashboardData(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $servers = $this->status->enrich($this->servers->dashboardServers());
        $result = [];

        foreach ($servers as $server) {
            $metrics = [];
            foreach (['cpu_load', 'ram_used'] as $metricName) {
                if (isset($server['latest_metrics'][$metricName])) {
                    $metrics[$metricName] = [
                        'value' => $server['latest_metrics'][$metricName]['value'],
                        'unit' => $server['latest_metrics'][$metricName]['unit'] ?? '%',
                    ];
                }
            }

            $disk = $server['latest_metrics']['disk_used_root']
                ?? $server['latest_metrics']['disk_used']
                ?? null;
            if ($disk !== null) {
                $metrics['disk'] = [
                    'value' => $disk['value'],
                    'unit' => $disk['unit'] ?? '%',
                ];
            }

            $result[] = [
                'id' => $server['id'],
                'status' => $server['status'],
                'last_metrics_at' => $server['last_metrics_at'] === null
                    ? null
                    : (new DateTimeImmutable((string) $server['last_metrics_at']))->format(DATE_ATOM),
                'seconds_since_update' => $server['seconds_since_update'],
                'active_alerts' => $server['active_alerts'],
                'metrics' => $metrics,
            ];
        }

        $response->getBody()->write(json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Compatibility fallback for direct controller construction in tests and
     * downstream integrations that have not injected IncidentRepository yet.
     * Production Bootstrap wires the repository and therefore renders concrete
     * incidents rather than this server-level approximation.
     *
     * @param list<array<string, mixed>> $servers
     * @return list<array<string, mixed>>
     */
    private function fallbackAttention(array $servers): array
    {
        $issues = array_values(array_filter(
            $servers,
            static fn (array $server): bool => in_array(
                (string) ($server['status'] ?? 'offline'),
                ['warning', 'critical', 'offline'],
                true
            )
        ));
        $priority = ['critical' => 0, 'offline' => 1, 'warning' => 2];
        usort($issues, static function (array $left, array $right) use ($priority): int {
            $leftStatus = (string) ($left['status'] ?? 'offline');
            $rightStatus = (string) ($right['status'] ?? 'offline');
            $statusOrder = ($priority[$leftStatus] ?? 99) <=> ($priority[$rightStatus] ?? 99);
            if ($statusOrder !== 0) {
                return $statusOrder;
            }

            $alertsOrder = (int) ($right['active_alerts'] ?? 0) <=> (int) ($left['active_alerts'] ?? 0);
            if ($alertsOrder !== 0) {
                return $alertsOrder;
            }

            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return array_map(static function (array $server): array {
            $status = (string) ($server['status'] ?? 'offline');
            return [
                'server_id' => $server['id'] ?? 0,
                'server_name' => $server['name'] ?? '',
                'group_id' => $server['group_id'] ?? null,
                'group_name' => $server['group_name'] ?? null,
                'kind' => $status === 'offline' ? 'offline' : 'metric',
                'subject_name' => $status,
                'severity' => $status === 'warning' ? 'warning' : 'critical',
                'created_at' => $server['last_metrics_at'] ?? null,
            ];
        }, array_slice($issues, 0, 6));
    }
}
