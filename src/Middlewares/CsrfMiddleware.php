<?php
// src/Middlewares/CsrfMiddleware.php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;
use Slim\Csrf\Guard;

class CsrfMiddleware
{
    private $twig;
    private $csrf;

    public function __construct(Twig $twig, Guard $csrf)
    {
        $this->twig = $twig;
        $this->csrf = $csrf;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Пропускаем через handler, чтобы Guard мог установить атрибуты
        $response = $handler->handle($request);
        
        // После обработки получаем токены
        $csrfNameKey = $this->csrf->getTokenNameKey();
        $csrfValueKey = $this->csrf->getTokenValueKey();
        
        $csrfName = $request->getAttribute($csrfNameKey);
        $csrfValue = $request->getAttribute($csrfValueKey);
        
        $this->twig->getEnvironment()->addGlobal('csrf', [
            'name_key' => $csrfNameKey,
            'value_key' => $csrfValueKey,
            'name' => $csrfName,
            'value' => $csrfValue
        ]);

        return $response;
    }
}
