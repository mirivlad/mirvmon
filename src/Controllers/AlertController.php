<?php
// src/Controllers/AlertController.php

namespace App\Controllers;

use App\Models\Model;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AlertController extends Model
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
            SELECT a.*, s.name as server_name 
            FROM alerts a
            JOIN servers s ON a.server_id = s.id
            WHERE a.resolved = 0
            ORDER BY a.created_at DESC
        ");
        $stmt->execute();
        $alerts = $stmt->fetchAll();

        $templateData = [
            'title' => 'Алерты',
            'alerts' => $alerts
        ];

        return $this->twig->render($response, 'alerts/index.twig', $templateData);
    }

    public function markAsResolved(Request $request, Response $response, $args)
    {
        $id = $args['id'];

        $stmt = $this->pdo->prepare("
            UPDATE alerts 
            SET resolved = 1, resolved_at = NOW() 
            WHERE id = :id
        ");

        $result = $stmt->execute([':id' => $id]);

        if ($result) {
            return $response->withHeader('Location', '/alerts')->withStatus(302);
        } else {
            // TODO: Обработка ошибки
            return $response->withHeader('Location', '/alerts')->withStatus(302);
        }
    }
}