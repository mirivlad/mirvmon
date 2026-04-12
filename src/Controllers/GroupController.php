<?php
// src/Controllers/GroupController.php

namespace App\Controllers;

use App\Models\Model;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class GroupController extends Model
{
    private $twig;

    public function __construct(Twig $twig)
    {
        parent::__construct();
        $this->twig = $twig;
    }

    public function index(Request $request, Response $response, $args)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM server_groups ORDER BY name");
        $stmt->execute();
        $groups = $stmt->fetchAll();

        $templateData = [
            'title' => 'Группы серверов',
            'groups' => $groups
        ];

        return $this->twig->render($response, 'groups/index.twig', $templateData);
    }

    public function create(Request $request, Response $response, $args)
    {
        $templateData = [
            'title' => 'Создать группу'
        ];

        return $this->twig->render($response, 'groups/create.twig', $templateData);
    }

    public function store(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();

        $stmt = $this->pdo->prepare("
            INSERT INTO server_groups (name, description, icon, color)
            VALUES (:name, :description, :icon, :color)
        ");

        $result = $stmt->execute([
            ':name' => $params['name'],
            ':description' => $params['description'] ?? '',
            ':icon' => $params['icon'] ?? '',
            ':color' => $params['color'] ?? ''
        ]);

        if ($result) {
            return $response->withHeader('Location', '/groups')->withStatus(302);
        } else {
            // TODO: Обработка ошибки
            return $response->withHeader('Location', '/groups/create')->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, $args)
    {
        $id = $args['id'];

        $stmt = $this->pdo->prepare("SELECT * FROM server_groups WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $group = $stmt->fetch();

        if (!$group) {
            return $response->withHeader('Location', '/groups')->withStatus(302);
        }

        $templateData = [
            'title' => 'Редактировать группу',
            'group' => $group
        ];

        return $this->twig->render($response, 'groups/edit.twig', $templateData);
    }

    public function update(Request $request, Response $response, $args)
    {
        $id = $args['id'];
        $params = $request->getParsedBody();

        $stmt = $this->pdo->prepare("
            UPDATE server_groups
            SET name = :name, description = :description, icon = :icon, color = :color
            WHERE id = :id
        ");

        $result = $stmt->execute([
            ':id' => $id,
            ':name' => $params['name'],
            ':description' => $params['description'] ?? '',
            ':icon' => $params['icon'] ?? '',
            ':color' => $params['color'] ?? ''
        ]);

        if ($result) {
            return $response->withHeader('Location', '/groups')->withStatus(302);
        } else {
            // TODO: Обработка ошибки
            return $response->withHeader('Location', '/groups/' . $id . '/edit')->withStatus(302);
        }
    }

    public function delete(Request $request, Response $response, $args)
    {
        $id = $args['id'];

        $stmt = $this->pdo->prepare("DELETE FROM server_groups WHERE id = :id");
        $result = $stmt->execute([':id' => $id]);

        if ($result) {
            return $response->withHeader('Location', '/groups')->withStatus(302);
        } else {
            // TODO: Обработка ошибки
            return $response->withHeader('Location', '/groups')->withStatus(302);
        }
    }

    public function show(Request $request, Response $response, $args)
    {
        $id = $args['id'];

        // Получаем информацию о группе
        $stmt = $this->pdo->prepare("SELECT * FROM server_groups WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $group = $stmt->fetch();

        if (!$group) {
            return $response->withHeader('Location', '/groups')->withStatus(302);
        }

        // Получаем серверы в этой группе
        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   (SELECT created_at FROM server_metrics sm WHERE sm.server_id = s.id ORDER BY sm.created_at DESC LIMIT 1) as last_metrics_at
            FROM servers s
            WHERE s.group_id = :group_id
            ORDER BY s.name
        ");
        $stmt->execute([':group_id' => $id]);
        $servers = $stmt->fetchAll();

        // Получаем статистику для каждого сервера
        $serverStats = [];
        foreach ($servers as $server) {
            $stmt = $this->pdo->prepare("
                SELECT mn.name, sm.value, mn.unit
                FROM server_metrics sm
                JOIN metric_names mn ON sm.metric_name_id = mn.id
                WHERE sm.server_id = :server_id
                ORDER BY sm.created_at DESC
                LIMIT 5
            ");
            $stmt->execute([':server_id' => $server['id']]);

            $metrics = [];
            while ($row = $stmt->fetch()) {
                if (!isset($metrics[$row['name']])) {
                    $metrics[$row['name']] = [
                        'value' => $row['value'],
                        'unit' => $row['unit']
                    ];
                }
            }

            $serverStats[$server['id']] = $metrics;
        }

        $templateData = [
            'title' => 'Группа: ' . $group['name'],
            'group' => $group,
            'servers' => $servers,
            'serverStats' => $serverStats
        ];

        return $this->twig->render($response, 'groups/show.twig', $templateData);
    }
}
