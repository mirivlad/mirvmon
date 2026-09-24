<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\ObservationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ObservationController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly ObservationRepository $observations,
        private readonly Translator $translator = new Translator()
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $view = (string) ($request->getQueryParams()['view'] ?? 'active');
        if (!in_array($view, ['active', 'history', 'normal'], true)) {
            $view = 'active';
        }
        $rows = match ($view) {
            'history' => $this->observations->history(),
            'normal' => $this->observations->acceptedNormal(),
            default => $this->observations->active(),
        };

        return $this->twig->render($response, 'observations/index.twig', [
            'title' => $this->translator->trans('observations.title'),
            'view' => $view,
            'observations' => $rows,
        ]);
    }

    /** @param array<string, string> $args */
    public function handle(Request $request, Response $response, array $args): Response
    {
        $id = $this->id($args);
        if ($id === null) {
            return $this->flashRedirect($response, 'observations.flash.not_found', 'error');
        }
        $ok = $this->observations->handle(
            $id,
            $this->userId(),
            $this->username()
        );
        return $this->flashRedirect(
            $response,
            $ok ? 'observations.flash.handled' : 'observations.flash.no_change',
            $ok ? 'success' : 'warning'
        );
    }
    /** @param array<string, string> $args */
    public function acceptNormal(Request $request, Response $response, array $args): Response
    {
        $id = $this->id($args);
        if ($id === null) {
            return $this->flashRedirect($response, 'observations.flash.not_found', 'error');
        }
        $ok = $this->observations->acceptNormal(
            $id,
            $this->userId(),
            $this->username()
        );
        return $this->flashRedirect(
            $response,
            $ok ? 'observations.flash.accepted' : 'observations.flash.no_change',
            $ok ? 'success' : 'warning'
        );
    }

    /** @param array<string, string> $args */
    public function resetNormal(Request $request, Response $response, array $args): Response
    {
        $id = $this->id($args);
        if ($id === null) {
            return $this->flashRedirect($response, 'observations.flash.not_found', 'error');
        }
        $ok = $this->observations->resetAcceptedNormal($id);
        return $this->flashRedirect(
            $response,
            $ok ? 'observations.flash.reset' : 'observations.flash.no_change',
            $ok ? 'success' : 'warning'
        );
    }

    /** @param array<string, string> $args */
    public function assess(Request $request, Response $response, array $args): Response
    {
        $id = $this->id($args);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $cycle = filter_var($body['recurrence_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $outcome = is_string($body['outcome'] ?? null) ? $body['outcome'] : '';
        $view = in_array($body['view'] ?? '', ['active', 'history', 'normal'], true)
            ? (string) $body['view'] : 'active';
        if ($id === null || $cycle === false) {
            return $this->flashRedirect($response, 'observations.flash.no_change', 'warning', $view);
        }
        $ok = $this->observations->assess(
            $id, (int) $cycle, $outcome, $this->userId(), $this->username()
        );
        return $this->flashRedirect(
            $response,
            $ok ? 'observations.assessment.saved' : 'observations.flash.no_change',
            $ok ? 'success' : 'warning',
            $view
        );
    }
    /** @param array<string, string> $args */
    private function id(array $args): ?int
    {
        $id = filter_var(
            $args['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        return $id === false ? null : (int) $id;
    }

    private function userId(): ?int
    {
        $id = filter_var(
            $_SESSION['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        return $id === false ? null : (int) $id;
    }

    private function username(): ?string
    {
        $username = $_SESSION['username'] ?? null;
        return is_string($username) ? $username : null;
    }

    private function flashRedirect(Response $response, string $key, string $type, string $view = 'active'): Response
    {
        $_SESSION['flash_message'] = $this->translator->trans($key);
        $_SESSION['flash_type'] = $type;
        $location = $view === 'active' ? '/observations' : '/observations?view=' . $view;
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
