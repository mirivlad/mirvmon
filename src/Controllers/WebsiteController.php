<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\WebsiteCheckQueueRepository;
use App\Repositories\WebsiteRepository;
use App\Services\WebsiteEndpointValidator;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class WebsiteController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly WebsiteRepository $websites,
        private readonly WebsiteEndpointValidator $validator,
        private readonly WebsiteCheckQueueRepository $queue,
        private readonly Translator $translator = new Translator(),
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        try {
            $filters = $request->getQueryParams();
            return $this->twig->render($response, 'sites/index.twig', [
                'title' => $this->translator->trans('websites.title'),
                'groups' => $this->websites->groupedList($filters),
                'group_options' => $this->websites->groups(),
                'filters' => [
                    'search' => (string) ($filters['search'] ?? ''),
                    'group_id' => (string) ($filters['group_id'] ?? ''),
                    'status' => (string) ($filters['status'] ?? ''),
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->flash($exception->getMessage(), 'error');
            return $this->redirect($response, '/sites');
        }
    }

    /** @param array<string, string> $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        return $this->renderForm($response, 'sites/create.twig', $this->emptyForm(), 200);
    }

    /** @param array<string, string> $args */
    public function store(Request $request, Response $response, array $args): Response
    {
        $body = $this->body($request);
        try {
            $websiteId = $this->websites->create(
                $this->siteInput($body),
                $this->endpointInputs($body, false),
            );
            $this->flashKey('websites.flash.created', 'success');
            return $this->redirect($response, '/sites/' . $websiteId . '/edit');
        } catch (InvalidArgumentException $exception) {
            return $this->renderForm($response, 'sites/create.twig', $this->safeForm($body), 422, $exception->getMessage());
        } catch (Throwable) {
            $this->flashKey('websites.flash.save_failed', 'error');
            return $this->redirect($response, '/sites/create');
        }
    }

    /** @param array<string, string> $args */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        $website = $websiteId === null ? null : $this->websites->detail($websiteId);
        if ($website === null) {
            $this->flashKey('websites.flash.not_found', 'error');
            return $this->redirect($response, '/sites');
        }

        return $this->renderForm($response, 'sites/edit.twig', $website, 200);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        $body = $this->body($request);
        if ($websiteId === null) {
            return $this->redirect($response, '/sites');
        }
        try {
            $this->websites->update(
                $websiteId,
                $this->siteInput($body),
                $this->endpointInputs($body, true),
            );
            $this->flashKey('websites.flash.updated', 'success');
            return $this->redirect($response, '/sites/' . $websiteId . '/edit');
        } catch (InvalidArgumentException $exception) {
            $form = $this->safeForm($body);
            $form['id'] = $websiteId;
            return $this->renderForm($response, 'sites/edit.twig', $form, 422, $exception->getMessage());
        } catch (Throwable) {
            $this->flashKey('websites.flash.save_failed', 'error');
            return $this->redirect($response, '/sites/' . $websiteId . '/edit');
        }
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        if ($websiteId !== null) {
            try {
                $this->websites->delete($websiteId);
                $this->flashKey('websites.flash.deleted', 'success');
            } catch (Throwable) {
                $this->flashKey('websites.flash.delete_failed', 'error');
            }
        }
        return $this->redirect($response, '/sites');
    }

    /** @param array<string, string> $args */
    public function pause(Request $request, Response $response, array $args): Response
    {
        return $this->toggle($response, $args, false);
    }

    /** @param array<string, string> $args */
    public function resume(Request $request, Response $response, array $args): Response
    {
        return $this->toggle($response, $args, true);
    }

    /** @param array<string, string> $args */
    public function check(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        if ($websiteId !== null) {
            try {
                $count = $this->queue->enqueueManual($websiteId, new DateTimeImmutable());
                $this->flash(
                    $count > 0
                        ? $this->translator->trans('websites.flash.check_queued')
                        : $this->translator->trans('websites.flash.not_found'),
                    $count > 0 ? 'success' : 'warning',
                );
            } catch (Throwable) {
                $this->flashKey('websites.flash.check_failed', 'error');
            }
        }
        return $this->redirect($response, '/sites');
    }

    /** @param array<string, string> $args */
    private function toggle(Response $response, array $args, bool $resume): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        if ($websiteId !== null) {
            try {
                if ($resume) {
                    $this->websites->resume($websiteId, new DateTimeImmutable());
                } else {
                    $this->websites->pause($websiteId, new DateTimeImmutable());
                }
                $this->flashKey($resume ? 'websites.flash.resumed' : 'websites.flash.paused', 'success');
            } catch (Throwable) {
                $this->flashKey('websites.flash.save_failed', 'error');
            }
        }
        return $this->redirect($response, '/sites');
    }

    /** @param array<string, mixed> $site */
    private function renderForm(Response $response, string $template, array $site, int $status, ?string $error = null): Response
    {
        return $this->twig->render($response, $template, [
            'title' => $this->translator->trans('websites.form.title'),
            'website' => $site,
            'groups' => $this->websites->groups(),
            'form_error' => $error,
        ])->withStatus($status);
    }

    /** @return array<string, mixed> */
    private function emptyForm(): array
    {
        return [
            'name' => '', 'description' => '', 'group_id' => null,
            'registration_domain' => '', 'domain_check_enabled' => false,
            'notification_telegram_chat_id' => '', 'notification_emails' => [],
            'endpoints' => [[
                'id' => null, 'name' => 'Главная', 'is_primary' => true, 'url' => '', 'method' => 'GET',
                'interval_seconds' => 60, 'timeout_seconds' => 15, 'follow_redirects' => true,
                'max_redirects' => 10, 'status_check_enabled' => true,
                'expected_statuses' => '200-299', 'content_checks' => [],
                'warning_total_ms' => null, 'critical_total_ms' => null,
                'auth_type' => 'none', 'auth_username' => '', 'has_auth' => false,
                'headers' => [], 'has_headers' => false, 'credential_redirect_hosts' => [],
                'allow_self_signed' => false, 'tls_expiry_enabled' => false,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function siteInput(array $body): array
    {
        return [
            'name' => $body['name'] ?? '',
            'description' => $body['description'] ?? null,
            'group_id' => $body['group_id'] ?? null,
            'registration_domain' => $body['registration_domain'] ?? null,
            'domain_check_enabled' => $body['domain_check_enabled'] ?? false,
            'default_interval_seconds' => $body['default_interval_seconds'] ?? null,
            'tls_warning_days' => $body['tls_warning_days'] ?? null,
            'tls_critical_days' => $body['tls_critical_days'] ?? null,
            'domain_warning_days' => $body['domain_warning_days'] ?? null,
            'domain_critical_days' => $body['domain_critical_days'] ?? null,
            'notification_telegram_chat_id' => $body['notification_telegram_chat_id'] ?? null,
            'notification_emails' => $body['notification_emails'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return list<\App\Domain\Websites\WebsiteEndpointDefinition>
     */
    private function endpointInputs(array $body, bool $existing): array
    {
        $raw = $body['endpoints'] ?? [];
        if (!is_array($raw)) {
            throw new InvalidArgumentException($this->translator->trans('websites.validation.endpoints'));
        }
        $endpoints = [];
        foreach (array_values($raw) as $endpoint) {
            if (!is_array($endpoint)) {
                throw new InvalidArgumentException($this->translator->trans('websites.validation.endpoints'));
            }
            $input = $endpoint;
            if (isset($input['id']) && $input['id'] !== '') {
                $input['id'] = $input['id'];
            } elseif (!$existing) {
                unset($input['id']);
            }
            $input['is_primary'] = $input['is_primary'] ?? false;
            $input['credential_redirect_hosts'] = $this->listValue($input['credential_redirect_hosts'] ?? []);
            if (array_key_exists('headers', $input)) {
                $input['headers'] = $this->headers($input['headers']);
            } elseif (trim((string) ($input['headers_text'] ?? '')) !== '') {
                $input['headers'] = $this->headers($input['headers_text']);
            } elseif (($input['clear_headers'] ?? false) === true || ($input['clear_headers'] ?? '') === '1') {
                $input['headers'] = [];
                $input['clear_headers'] = true;
            }
            $input['content_checks'] = $this->contentChecks(
                $input['content_checks'] ?? $input['content_checks_text'] ?? []
            );
            $endpoints[] = $this->validator->validate($input);
        }
        if ($endpoints === []) {
            throw new InvalidArgumentException($this->translator->trans('websites.validation.endpoints'));
        }
        return $endpoints;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function safeForm(array $body): array
    {
        $safe = $body;
        if (isset($safe['endpoints']) && is_array($safe['endpoints'])) {
            foreach ($safe['endpoints'] as &$endpoint) {
                if (!is_array($endpoint)) {
                    continue;
                }
                $endpoint['auth_secret'] = '';
                if (isset($endpoint['headers']) && is_array($endpoint['headers'])) {
                    foreach ($endpoint['headers'] as &$header) {
                        if (is_array($header)) {
                            $header['value'] = '';
                        }
                    }
                    unset($header);
                }
            }
            unset($endpoint);
        }
        return $safe;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function listValue(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', preg_split('/[,;\r\n]+/', $value) ?: [])));
        }
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = (string) $item;
        }
        return $result;
    }

    /** @return list<array{name: string, value: string}> */
    private function headers(mixed $value): array
    {
        if (!is_string($value)) {
            return is_array($value) ? array_values($value) : [];
        }
        $headers = [];
        foreach (preg_split('/\r?\n/', $value) ?: [] as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $headers[] = [
                'name' => trim(substr($line, 0, $separator)),
                'value' => trim(substr($line, $separator + 1)),
            ];
        }
        return $headers;
    }

    /** @return list<array{kind: string, selector?: string, needle: string}> */
    private function contentChecks(mixed $value): array
    {
        if (!is_string($value)) {
            return is_array($value) ? array_values($value) : [];
        }
        $checks = [];
        foreach (preg_split('/\r?\n/', $value) ?: [] as $needle) {
            if (trim($needle) !== '') {
                $checks[] = ['kind' => 'page_text', 'needle' => $needle];
            }
        }
        return $checks;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function flashKey(string $key, string $type): void
    {
        $this->flash($this->translator->trans($key), $type);
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
