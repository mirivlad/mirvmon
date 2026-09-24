<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\PublicStatusRepository;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PublicStatusController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly PublicStatusRepository $status,
        private readonly Translator $translator,
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string,string> $args */
    public function page(Request $request, Response $response, array $args): Response
    {
        return $this->twig->render($response, 'status/public.twig', [
            'title' => $this->translator->trans('public_status.title'),
            'items' => $this->status->published(),
        ])->withHeader('Cache-Control', 'no-store')
          ->withHeader('X-Robots-Tag', 'noindex');
    }

    /** @param array<string,string> $args */
    public function settings(Request $request, Response $response, array $args): Response
    {
        return $this->twig->render($response, 'admin/public-status.twig', [
            'title' => $this->translator->trans('public_status.settings'),
            'choices' => $this->status->choices(),
        ]);
    }

    /** @param array<string,string> $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        try {
            $this->status->replace(is_array($body) && is_array($body['items'] ?? null) ? $body['items'] : []);
            $_SESSION['flash_message'] = $this->translator->trans('public_status.saved');
            $_SESSION['flash_type'] = 'success';
        } catch (InvalidArgumentException $exception) {
            $_SESSION['flash_message'] = $this->translator->trans('public_status.invalid');
            $_SESSION['flash_type'] = 'error';
        }
        return $response->withHeader('Location', '/admin/public-status')->withStatus(302);
    }
}
