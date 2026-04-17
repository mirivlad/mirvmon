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

        // Генерируем уникальный токен
        $token = bin2hex(random_bytes(16)); // 32-символьный токен
        
        $this->pdo->beginTransaction();
        
        try {
            // Сохраняем сервер
            $stmt = $this->pdo->prepare("
                INSERT INTO servers (name, address, group_id, description) 
                VALUES (:name, :address, :group_id, :description)
            ");

            $result = $stmt->execute([
                ':name' => $params['name'],
                ':address' => $params['address'] ?? '',
                ':group_id' => $params['group_id'] ?? null,
                ':description' => $params['description'] ?? ''
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
            
            // TODO: Обработка ошибки
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

        if (!$server) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }

        $templateData = [
            'title' => 'Редактировать сервер',
            'server' => $server,
            'groups' => $groups,
            'agent_token' => $decryptedToken
        ];

        return $this->twig->render($response, 'servers/edit.twig', $templateData);
    }

    public function update(Request $request, Response $response, $args)
    {
        $id = $args['id'];
        $params = $request->getParsedBody();

        $stmt = $this->pdo->prepare("
            UPDATE servers 
            SET name = :name, address = :address, group_id = :group_id, description = :description 
            WHERE id = :id
        ");

        $result = $stmt->execute([
            ':id' => $id,
            ':name' => $params['name'],
            ':address' => $params['address'] ?? '',
            ':group_id' => $params['group_id'] ?? null,
            ':description' => $params['description'] ?? ''
        ]);

        if ($result) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        } else {
            // TODO: Обработка ошибки
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
