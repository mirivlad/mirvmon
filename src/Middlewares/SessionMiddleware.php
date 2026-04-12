<?php
// src/Middlewares/SessionMiddleware.php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;

class SessionMiddleware
{
    private $twig;

    public function __construct(Twig $twig)
    {
        $this->twig = $twig;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Добавляем данные сессии в контекст Twig
        $sessionData = [
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role' => $_SESSION['role'] ?? null,
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_type' => $_SESSION['flash_type'] ?? null
        ];

        // Очищаем flash после чтения
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);

        // Получаем environment и добавляем session в глобальный контекст
        $environment = $this->twig->getEnvironment();
        $environment->addGlobal('session', $sessionData);

        return $handler->handle($request);
    }
}
