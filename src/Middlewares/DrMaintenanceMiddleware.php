<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Backup\DrMaintenanceLock;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DrMaintenanceMiddleware implements MiddlewareInterface
{
    /** @param list<string> $exemptPaths */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly DrMaintenanceLock $maintenance,
        private readonly array $exemptPaths = ['/livez', '/admin/system/restore/execute']
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $path = $request->getUri()->getPath();
        if ($this->isExempt($path)) {
            return $handler->handle($request);
        }

        $lease = $this->maintenance->acquireShared();
        if ($lease === null) {
            $response = $this->responseFactory->createResponse(503);
            $response->getBody()->write('MirvMon is temporarily unavailable during disaster recovery.');

            return $response
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Retry-After', '5')
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            return $handler->handle($request);
        } finally {
            $lease->release();
        }
    }

    private function isExempt(string $path): bool
    {
        foreach ($this->exemptPaths as $exempt) {
            if ($path === $exempt) {
                return true;
            }
        }

        return false;
    }
}
