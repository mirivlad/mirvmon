<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\Repositories\AppSettingsRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class LanguageController
{
    public function __construct(
        private readonly AppSettingsRepository $settings,
        private readonly Translator $translator
    ) {
    }

    /** @param array<string, string> $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $locale = is_string($body['ui_language'] ?? null)
            ? trim($body['ui_language'])
            : '';

        if (!$this->translator->isSupported($locale)) {
            $this->flash($this->translator->trans('settings.language.invalid'), 'error');

            return $this->redirect($response, '/admin/defaults');
        }

        try {
            $this->settings->set('ui_language', $locale);
            $this->translator->setLocale($locale);
            $this->flash($this->translator->trans('settings.language.saved'), 'success');
        } catch (Throwable) {
            $this->flash($this->translator->trans('common.error'), 'error');
        }

        return $this->redirect($response, '/admin/defaults');
    }

    private function flash(string $message, string $type): void
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
