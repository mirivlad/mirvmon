<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Security\RolePolicy;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OperatorMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if (RolePolicy::canOperate($_SESSION['role'] ?? null)) {
            return $handler->handle($request);
        }

        return $this->responseFactory->createResponse(403);
    }
}
