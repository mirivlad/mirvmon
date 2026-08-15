<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;
use Twig\TwigFunction;

final class LocaleMiddleware
{
    public function __construct(
        private readonly Translator $translator,
        Twig $twig
    ) {
        $environment = $twig->getEnvironment();
        if ($environment->getFunction('t') === null) {
            $environment->addFunction(new TwigFunction(
                't',
                $this->translator->trans(...)
            ));
            $environment->addFunction(new TwigFunction(
                'current_locale',
                $this->translator->locale(...)
            ));
            $environment->addFunction(new TwigFunction(
                'supported_locales',
                $this->translator->supportedLocales(...)
            ));
        }
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
