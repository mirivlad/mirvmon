<?php
// src/Controllers/AdminController.php

namespace App\Controllers;

use App\Models\Model;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AdminController extends Model
{
    private $twig;

    public function __construct(Twig $twig)
    {
        parent::__construct();
        $this->twig = $twig;
    }

    public function usersList(Request $request, Response $response, $args)
    {
        // Только для администраторов
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        
        $stmt = $this->pdo->prepare("SELECT id, username, email, role, created_at FROM users ORDER BY created_at DESC");
        $stmt->execute();
        $users = $stmt->fetchAll();

        $templateData = [
            'title' => 'Управление пользователями',
            'users' => $users
        ];

        return $this->twig->render($response, 'admin/users.twig', $templateData);
    }

    public function notificationSettings(Request $request, Response $response, $args)
    {
        // Только для администраторов
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $templateData = [
            'title' => 'Настройки уведомлений'
        ];

        return $this->twig->render($response, 'admin/notifications.twig', $templateData);
    }
}