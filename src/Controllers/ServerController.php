<?php
// src/Controllers/ServerController.php

namespace App\Controllers;

use App\Models\Model;
use App\Utils\EncryptionHelper;
use Config\DatabaseConfig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ServerController extends Model
{
    private $twig;

    public function __construct(Twig $twig)
    {
        parent::__construct();
        $this->twig = $twig;
    }

    public function index(Request $request, Response $response, $args)
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, sg.name as group_name 
            FROM servers s 
            LEFT JOIN server_groups sg ON s.group_id = sg.id 
            ORDER BY s.name
        ");
        $stmt->execute();
        $servers = $stmt->fetchAll();

        $templateData = [
            'title' => 'Серверы',
            'servers' => $servers
        ];

        return $this->twig->render($response, 'servers/index.twig', $templateData);
    }

    public function create(Request $request, Response $response, $args)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM server_groups ORDER BY name");
        $stmt->execute();
        $groups = $stmt->fetchAll();

        $templateData = [
            'title' => 'Добавить сервер',
            'groups' => $groups
        ];

        return $this->twig->render($response, 'servers/create.twig', $templateData);
    }

    public function store(Request $request, Response $response, $args)
    {
        $params = $request->getParsedBody();

        // Получаем дефолтные значения
        $stmtDefault = $this->pdo->query("SELECT setting_key, setting_value FROM default_settings");
        $defaults = [];
        while ($row = $stmtDefault->fetch()) {
            $defaults[$row['setting_key']] = $row['setting_value'];
        }

        $defaultOfflineTimeout = (int)($defaults['default_offline_timeout'] ?? 300);

        // Генерируем уникальный токен
        $token = bin2hex(random_bytes(16));
        
        $this->pdo->beginTransaction();
        
        try {
            // Сохраняем сервер с дефолтными значениями
            $stmt = $this->pdo->prepare("
                INSERT INTO servers (name, address, group_id, description, offline_timeout, notify_on_offline) 
                VALUES (:name, :address, :group_id, :description, :offline_timeout, 1)
            ");

            $result = $stmt->execute([
                ':name' => $params['name'],
                ':address' => $params['address'] ?? '',
                ':group_id' => $params['group_id'] ?? null,
                ':description' => $params['description'] ?? '',
                ':offline_timeout' => $defaultOfflineTimeout
            ]);

            $serverId = $this->pdo->lastInsertId();

            // Сохраняем хеш токена и зашифрованный токен
            $tokenHash = hash('sha256', $token);
            $encryptedToken = EncryptionHelper::encrypt($token);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO agent_tokens (server_id, token_hash, encrypted_token) 
                VALUES (:server_id, :token_hash, :encrypted_token)
            ");

            $result = $stmt->execute([
                ':server_id' => $serverId,
                ':token_hash' => $tokenHash,
                ':encrypted_token' => $encryptedToken
            ]);

            $this->pdo->commit();

            // Передаем токен для отображения на странице
            $templateData = [
                'title' => 'Сервер добавлен',
                'server' => [
                    'id' => $serverId,
                    'name' => $params['name']
                ],
                'token' => $token
            ];

            return $this->twig->render($response, 'servers/created.twig', $templateData);

        } catch (\Exception $e) {
            $this->pdo->rollback();
            
            return $response->withHeader('Location', '/servers/create')->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, $args)
    {
        $id = $args['id'];

        $stmt = $this->pdo->prepare("SELECT * FROM servers WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $server = $stmt->fetch();

        $stmt = $this->pdo->prepare("SELECT * FROM server_groups ORDER BY name");
        $stmt->execute();
        $groups = $stmt->fetchAll();

        $stmt = $this->pdo->prepare("SELECT encrypted_token FROM agent_tokens WHERE server_id = :server_id");
        $stmt->execute([':server_id' => $id]);
        $tokenRow = $stmt->fetch();
        $decryptedToken = $tokenRow ? \App\Utils\EncryptionHelper::decrypt($tokenRow['encrypted_token']) : null;

        // Получаем все метрики которые есть у серве��а
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT mn.id, mn.name, mn.unit
            FROM metric_names mn
            JOIN server_metrics sm ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :server_id
            ORDER BY mn.name
        ");
        $stmt->execute([':server_id' => $id]);
        $allMetrics = $stmt->fetchAll();

        if (!$server) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }

        $templateData = [
            'title' => 'Редактировать сервер',
            'server' => $server,
            'groups' => $groups,
            'agent_token' => $decryptedToken,
            'allMetrics' => $allMetrics
        ];

        return $this->twig->render($response, 'servers/edit.twig', $templateData);
    }

    public function update(Request $request, Response $response, $args)
    {
        $id = $args['id'];
        $params = $request->getParsedBody();

        // Собираем выбранные метрики
        $displayMetrics = $params['display_metrics'] ?? [];
        $displayMetricsJson = json_encode(array_values($displayMetrics));

        $stmt = $this->pdo->prepare("
            UPDATE servers 
            SET name = :name, 
                address = :address, 
                group_id = :group_id, 
                description = :description,
                offline_timeout = :offline_timeout,
                notify_on_offline = :notify_on_offline,
                display_metrics = :display_metrics
            WHERE id = :id
        ");

        $result = $stmt->execute([
            ':id' => $id,
            ':name' => $params['name'],
            ':address' => $params['address'] ?? '',
            ':group_id' => $params['group_id'] ?? null,
            ':description' => $params['description'] ?? '',
            ':offline_timeout' => (int)($params['offline_timeout'] ?? 300),
            ':notify_on_offline' => isset($params['notify_on_offline']) ? 1 : 0,
            ':display_metrics' => $displayMetricsJson
        ]);

        if ($result) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        } else {
            return $response->withHeader('Location', '/servers/' . $id . '/edit')->withStatus(302);
        }
    }

    public function delete(Request $request, Response $response, $args)
    {
        $id = $args['id'];

        $stmt = $this->pdo->prepare("DELETE FROM servers WHERE id = :id");
        $result = $stmt->execute([':id' => $id]);

        if ($result) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        } else {
            // TODO: Обработка ошибки
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
    }
    
    public function regenerateToken(Request $request, Response $response, $args)
    {
        $id = $args['id'];
        
        // Генерируем новый токен
        $newToken = bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $newToken);
        $encryptedToken = EncryptionHelper::encrypt($newToken);
        
        // Обновляем или создаем запись в agent_tokens
        $stmt = $this->pdo->prepare("
            INSERT INTO agent_tokens (server_id, token_hash, encrypted_token) 
            VALUES (:server_id, :token_hash, :encrypted_token)
            ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), encrypted_token = VALUES(encrypted_token)
        ");
        
        $result = $stmt->execute([
            ':server_id' => $id,
            ':token_hash' => $tokenHash,
            ':encrypted_token' => $encryptedToken
        ]);

        if ($result) {
            // Перенаправляем обратно на страницу редактирования
            return $response->withHeader('Location', '/servers/' . $id . '/edit')->withStatus(302);
        } else {
            // TODO: Обработка ошибки
            return $response->withHeader('Location', '/servers/' . $id . '/edit')->withStatus(302);
        }
    }
}
