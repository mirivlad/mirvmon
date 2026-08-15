<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LocaleMiddleware
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if (!$this->isUiRequest($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $this->translator->refreshLocale();
        $response = $handler->handle($request);

        return $response->withHeader('Content-Language', $this->translator->locale());
    }

    private function isUiRequest(string $path): bool
    {
        return !str_starts_with($path, '/api/')
            && !str_starts_with($path, '/agent/binaries/')
            && $path !== '/agent/install.sh'
            && $path !== '/agent/install.exe'
            && $path !== '/livez'
            && $path !== '/readyz';
    }
}
