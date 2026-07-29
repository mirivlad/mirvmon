<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestSizeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly int $maximumBytes
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $contentLength = $request->getHeaderLine('Content-Length');
        $bodySize = $request->getBody()->getSize();
        $tooLarge = $contentLength !== ''
            && ctype_digit($contentLength)
            && (int) $contentLength > $this->maximumBytes;
        $tooLarge = $tooLarge
            || ($bodySize !== null && $bodySize > $this->maximumBytes);

        if ($tooLarge) {
            $response = $this->responseFactory->createResponse(413);
            $response->getBody()->write('Payload too large.');

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $handler->handle($request);
    }
}
