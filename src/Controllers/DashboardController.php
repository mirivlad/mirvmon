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
        // Получаем статистику
        $stats = $this->serverModel->getStats();

        // Получаем список серверов со статусами для цветных карточек
        $servers = $this->serverModel->getServersWithStatus();

        // Загружаем пороги для каждого сервера
        foreach ($servers as &$server) {
            $t = $this->serverModel->getThresholds($server['id']);
            $server['thresholds'] = $t;
            file_put_contents('/tmp/thresholds_debug.log', "Server {$server['id']}: " . json_encode($t) . "\n", FILE_APPEND);
        }
        unset($server);

        $templateData = [
            'title' => 'Дашборд мониторинга',
            'stats' => $stats,
            'servers' => $servers
        ];

        return $this->twig->render($response, 'dashboard.twig', $templateData);
    }
}
