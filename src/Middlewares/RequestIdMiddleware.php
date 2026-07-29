<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'mirvmon.request_id';

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $requestId = $request->getHeaderLine('X-Request-ID');
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $requestId) !== 1) {
            $requestId = bin2hex(random_bytes(16));
        }

        return $handler
            ->handle($request->withAttribute(self::ATTRIBUTE, $requestId))
            ->withHeader('X-Request-ID', $requestId);
    }
}
