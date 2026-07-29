<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Csrf\Guard;
use Slim\Views\Twig;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly Twig $twig
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $storage = null;
        $guard = new Guard(
            $this->responseFactory,
            'csrf',
            $storage,
            function (): ResponseInterface {
                $response = $this->responseFactory->createResponse(400);
                $response->getBody()->write('Invalid CSRF token.');

                return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
            },
            10,
            32,
            true
        );

        $twig = $this->twig;
        $guardHandler = new class($handler, $guard, $twig) implements RequestHandlerInterface {
            public function __construct(
                private readonly RequestHandlerInterface $handler,
                private readonly Guard $guard,
                private readonly Twig $twig
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $nameKey = $this->guard->getTokenNameKey();
                $valueKey = $this->guard->getTokenValueKey();
                $this->twig->getEnvironment()->addGlobal('csrf', [
                    'name_key' => $nameKey,
                    'value_key' => $valueKey,
                    'name' => $request->getAttribute($nameKey),
                    'value' => $request->getAttribute($valueKey),
                ]);

                return $this->handler->handle($request);
            }
        };

        return $guard->process($request, $guardHandler);
    }
}
