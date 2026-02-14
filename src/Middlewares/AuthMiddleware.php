<?php
// src/Middlewares/AuthMiddleware.php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Проверяем, авторизован ли пользователь
        if (!isset($_SESSION['user_id'])) {
            // Если не авторизован, перенаправляем на страницу входа
            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }
        
        // Если авторизован, продолжаем выполнение
        return $handler->handle($request);
    }
}