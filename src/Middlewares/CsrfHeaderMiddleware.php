<?php
// src/Middlewares/CsrfHeaderMiddleware.php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class CsrfHeaderMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $token = $request->getHeaderLine('X-CSRF-TOKEN');
        
        if ($token) {
            $parsedBody = $request->getParsedBody() ?? [];
            $parsedBody['csrf_value'] = $token;
            $request = $request->withParsedBody($parsedBody);
        }
        
        return $handler->handle($request);
    }
}
