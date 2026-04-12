<?php
// src/Middlewares/FlashMiddleware.php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class FlashMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Делаем flash доступным в Twig
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Передаём flash в глобальные переменные Twig
        // Это будет обработано в layout
        return $handler->handle($request);
    }
}
