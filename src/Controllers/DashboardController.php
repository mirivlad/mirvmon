<?php
// src/Controllers/DashboardController.php

namespace App\Controllers;

use App\Models\Server;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    private $twig;
    private $serverModel;

    public function __construct($twig)
    {
        $this->twig = $twig;
        $this->serverModel = new Server();
    }

    public function index(Request $request, Response $response, $args)
    {
        $stats = $this->serverModel->getStats();
        $servers = $this->serverModel->getServersWithStatus();

        $groups = [];
        $noGroupServers = [];

        foreach ($servers as $server) {
            if (empty($server['group_name'])) {
                $noGroupServers[] = $server;
            } else {
                $groups[$server['group_name']]['name'] = $server['group_name'];
                $groups[$server['group_name']]['color'] = $server['group_color'] ?? '#6c757d';
                $groups[$server['group_name']]['icon'] = $server['group_icon'] ?? 'fa-server';
                $groups[$server['group_name']]['servers'][] = $server;
            }
        }

        if (!empty($noGroupServers)) {
            $groups['Без группы'] = [
                'name' => 'Без группы',
                'color' => '#6c757d',
                'icon' => 'fa-server',
                'servers' => $noGroupServers
            ];
        }

        $templateData = [
            'title' => 'Дашборд мониторинга',
            'stats' => $stats,
            'groups' => $groups
        ];

        return $this->twig->render($response, 'dashboard.twig', $templateData);
    }

    public function getDashboardData(Request $request, Response $response, $args)
    {
        $servers = $this->serverModel->getServersWithStatus();

        $result = [];
        foreach ($servers as $server) {
            $serverData = [
                'id' => $server['id'],
                'status' => $server['status'],
                'updated_at' => $server['last_metrics_at'] ? date('d.m.Y H:i:s', strtotime($server['last_metrics_at'])) : 'Нет данных',
                'metrics' => []
            ];

            if (isset($server['latest_metrics']['cpu_load'])) {
                $serverData['metrics']['cpu_load'] = [
                    'value' => $server['latest_metrics']['cpu_load']['value'],
                    'unit' => $server['latest_metrics']['cpu_load']['unit'] ?? '%'
                ];
            }
            if (isset($server['latest_metrics']['ram_used'])) {
                $serverData['metrics']['ram_used'] = [
                    'value' => $server['latest_metrics']['ram_used']['value'],
                    'unit' => $server['latest_metrics']['ram_used']['unit'] ?? '%'
                ];
            }
            $diskMetric = $server['latest_metrics']['disk_used_root'] ?? $server['latest_metrics']['disk_used'] ?? null;
            if ($diskMetric) {
                $serverData['metrics']['disk'] = [
                    'value' => $diskMetric['value'],
                    'unit' => $diskMetric['unit'] ?? '%'
                ];
            }
            $result[] = $serverData;
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
