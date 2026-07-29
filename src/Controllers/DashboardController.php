<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ServerRepository;
use App\Services\ServerStatusService;
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
        private readonly ServerStatusService $status
    ) {
    }

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
                    'name' => $server['group_name'] ?: 'Без группы',
                    'color' => $server['group_color'] ?: '#6c757d',
                    'icon' => $server['group_icon'] ?: 'fa-server',
                    'servers' => [],
                ];
            }
            $groups[$groupKey]['servers'][] = $server;
        }

        return $this->twig->render($response, 'dashboard.twig', [
            'title' => 'Дашборд мониторинга',
            'stats' => $this->status->summary($servers, $this->servers->groupCount()),
            'groups' => $groups,
        ]);
    }

    /**
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
}
