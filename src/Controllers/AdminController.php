<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\AppSettingsRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Repositories\WorkerHeartbeatRepository;
use App\Services\ConnectivitySettingsService;
use App\Services\SystemHealthService;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class AdminController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly NotificationSettingsRepository $notificationSettings,
        private readonly NotificationOutboxRepository $notificationOutbox,
        private readonly WorkerHeartbeatRepository $heartbeats,
        private readonly Translator $translator = new Translator()
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function usersList(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $users = $this->pdo->query(
            <<<'SQL'
            SELECT
                users.id,
                users.username,
                users.email,
                users.role,
                users.created_at,
                settings.telegram_chat_id,
                settings.email_for_alerts
            FROM users
            LEFT JOIN user_notification_settings AS settings ON settings.user_id = users.id
            ORDER BY users.created_at DESC, users.id DESC
            SQL
        )?->fetchAll() ?? [];

        return $this->twig->render($response, 'admin/users.twig', [
            'title' => $this->translator->trans('users.title'),
            'users' => $users,
        ]);
    }

    /** @param array<string, string> $args */
    public function saveUser(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        try {
            $input = $this->validatedUserInput($body);
        } catch (InvalidArgumentException $exception) {
            $this->flash($exception->getMessage(), 'error');
            return $this->redirect($response, '/admin/users');
        }

        $ownsTransaction = $this->beginTransaction('admin_save_user');
        try {
            $this->lockAdministration();
            if ($input['user_id'] === null) {
                $userId = $this->createUser($input);
                $message = $this->translator->trans('admin.user.created', ['name' => $input['username']]);
            } else {
                $this->updateUser($input);
                $userId = $input['user_id'];
                $message = $this->translator->trans('admin.user.updated', ['name' => $input['username']]);
            }
            $this->saveUserNotifications($userId, $input['telegram_chat_id'], $input['email_for_alerts']);
            $this->commitTransaction($ownsTransaction, 'admin_save_user');
            $this->flash($message, 'success');
        } catch (InvalidArgumentException $exception) {
            $this->rollbackTransaction($ownsTransaction, 'admin_save_user');
            $this->flash($exception->getMessage(), 'error');
        } catch (PDOException $exception) {
            $this->rollbackTransaction($ownsTransaction, 'admin_save_user');
            $this->flashKey(
                $exception->getCode() === '23505' ? 'admin.user.duplicate' : 'admin.user.save_failed',
                'error'
            );
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'admin_save_user');
            $this->flashKey('admin.user.save_failed', 'error');
        }
        return $this->redirect($response, '/admin/users');
    }

    /** @param array<string, string> $args */
    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $userId = $this->positiveInteger($args['id'] ?? null);
        if ($userId === null) {
            $this->flashKey('admin.user.not_found', 'error');
            return $this->redirect($response, '/admin/users');
        }
        if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {
            $this->flashKey('admin.user.delete_self', 'error');
            return $this->redirect($response, '/admin/users');
        }

        $ownsTransaction = $this->beginTransaction('admin_delete_user');
        try {
            $this->lockAdministration();
            $statement = $this->pdo->prepare('SELECT username, role FROM users WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $userId]);
            $user = $statement->fetch();
            if (!is_array($user)) {
                throw new InvalidArgumentException($this->translator->trans('admin.user.not_found'));
            }
            if ($user['role'] === 'admin' && $this->administratorCount() <= 1) {
                throw new InvalidArgumentException($this->translator->trans('admin.user.delete_last_admin'));
            }
            $delete = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
            $delete->execute(['id' => $userId]);
            $this->commitTransaction($ownsTransaction, 'admin_delete_user');
            $this->flash(
                $this->translator->trans('admin.user.deleted', ['name' => (string) $user['username']]),
                'success'
            );
        } catch (InvalidArgumentException $exception) {
            $this->rollbackTransaction($ownsTransaction, 'admin_delete_user');
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'admin_delete_user');
            $this->flashKey('admin.user.delete_failed', 'error');
        }
        return $this->redirect($response, '/admin/users');
    }

    /** @param array<string, string> $args */
    public function notificationSettings(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        return $this->twig->render($response, 'admin/notifications.twig', [
            'title' => $this->translator->trans('notifications.title'),
            'settings' => $this->notificationSettings->getPublic(),
        ]);
    }

    /** @param array<string, string> $args */
    public function saveNotificationSettings(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        try {
            $this->notificationSettings->save(is_array($body) ? $body : []);
            $this->flashKey('admin.notifications.saved', 'success');
        } catch (InvalidArgumentException $exception) {
            $this->flash(
                $this->translator->trans('admin.notifications.invalid', ['error' => $exception->getMessage()]),
                'error'
            );
        } catch (Throwable) {
            $this->flashKey('admin.notifications.save_failed', 'error');
        }
        return $this->redirect($response, '/admin/notifications');
    }

    /** @param array<string, string> $args */
    public function notificationQueue(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $query = $request->getQueryParams();
        $filters = $this->notificationOutbox->filters($query);
        $page = $this->positiveInteger($query['page'] ?? null) ?? 1;
        $queue = $this->notificationOutbox->page($filters, $page);
        $page = min($page, $queue['pages']);
        return $this->twig->render($response, 'admin/notification-queue.twig', [
            'title' => $this->translator->trans('queue.title'),
            'filters' => $filters,
            'queue' => $queue,
            'queue_url' => $this->queueLocation($filters),
            'page' => $page,
            'queue_filter_fields' => $this->queueFilterFields($filters),
            'queue_counts' => $this->notificationOutbox->statusCounts(),
            'servers' => $this->pdo->query('SELECT id, name FROM servers ORDER BY name, id')?->fetchAll() ?? [],
            'heartbeats' => $this->heartbeats->all(),
        ]);
    }

    /** @param array<string, string> $args */
    public function retryNotificationQueue(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        $filters = $this->notificationOutbox->filters(is_array($body) ? $body : []);
        try {
            $requeued = $this->notificationOutbox->retryUndelivered();
            $this->flash(
                $this->translator->trans('admin.queue.retry_many', ['count' => $requeued]),
                'success'
            );
        } catch (Throwable) {
            $this->flashKey('admin.queue.retry_many_failed', 'error');
        }
        return $this->redirect($response, $this->queueLocation($filters));
    }

    /** @param array<string, string> $args */
    public function retryNotificationJob(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        $filters = $this->notificationOutbox->filters(is_array($body) ? $body : []);
        $jobId = $this->positiveInteger($args['id'] ?? null);
        if ($jobId === null) {
            $this->flashKey('admin.queue.job_not_found', 'error');
            return $this->redirect($response, $this->queueLocation($filters));
        }
        try {
            $requeued = $this->notificationOutbox->retryByIds([$jobId]);
            $this->flashKey(
                $requeued === 1 ? 'admin.queue.retry_one' : 'admin.queue.retry_not_allowed',
                $requeued === 1 ? 'success' : 'warning'
            );
        } catch (Throwable) {
            $this->flashKey('admin.queue.retry_one_failed', 'error');
        }
        return $this->redirect($response, $this->queueLocation($filters));
    }

    /** @param array<string, string> $args */
    public function deleteNotificationJob(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        $filters = $this->notificationOutbox->filters(is_array($body) ? $body : []);
        $jobId = $this->positiveInteger($args['id'] ?? null);
        if ($jobId === null) {
            $this->flashKey('admin.queue.job_not_found', 'error');
        } else {
            try {
                $deleted = $this->notificationOutbox->deleteByIds([$jobId]);
                $this->flashKey(
                    $deleted === 1 ? 'admin.queue.job_deleted' : 'admin.queue.job_not_found',
                    $deleted === 1 ? 'success' : 'warning'
                );
            } catch (Throwable) {
                $this->flashKey('admin.queue.delete_one_failed', 'error');
            }
        }
        return $this->redirect($response, $this->queueLocation($filters));
    }

    /** @param array<string, string> $args */
    public function deleteNotificationQueue(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $filters = $this->notificationOutbox->filters($body);
        if (($body['confirm_delete'] ?? null) !== '1') {
            $this->flash(
                $this->translator->trans('admin.queue.confirm_delete', [
                    'count' => $this->notificationOutbox->countMatching($filters),
                ]),
                'warning'
            );
            return $this->redirect($response, $this->queueLocation($filters));
        }
        try {
            $deleted = $this->notificationOutbox->deleteMatching($filters);
            $this->flash(
                $this->translator->trans('admin.queue.deleted_many', ['count' => $deleted]),
                'success'
            );
        } catch (Throwable) {
            $this->flashKey('admin.queue.delete_many_failed', 'error');
        }
        return $this->redirect($response, $this->queueLocation($filters));
    }

    /** @param array<string, string> $args */
    public function testNotification(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        try {
            $this->notificationSettings->save(is_array($body) ? $body : []);
            $queued = $this->notificationOutbox->enqueueTest([
                'type' => 'test',
                'event' => 'test',
                'event_time' => (new DateTimeImmutable())->format(DATE_ATOM),
            ]);
            if ($queued === 0) {
                throw new InvalidArgumentException(
                    $this->translator->trans('admin.notifications.test_channel_required')
                );
            }
            $this->flash(
                $this->translator->trans('admin.notifications.test_queued', ['count' => $queued]),
                'success'
            );
        } catch (InvalidArgumentException $exception) {
            $this->flash(
                $this->translator->trans('admin.notifications.test_failed', ['error' => $exception->getMessage()]),
                'error'
            );
        } catch (Throwable) {
            $this->flashKey('admin.notifications.test_queue_failed', 'error');
        }
        return $this->redirect($response, '/admin/notifications');
    }

    /** @param array<string, string> $args */
    public function defaultSettings(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT setting_key, setting_value
            FROM app_settings
            WHERE setting_key IN (
                'default_offline_timeout',
                'default_warning_threshold',
                'default_critical_threshold',
                'default_duration_seconds',
                'default_recovery_duration_seconds',
                'website_default_interval_seconds',
                'website_http_timeout_seconds',
                'website_tls_warning_days',
                'website_tls_critical_days',
                'website_domain_warning_days',
                'website_domain_critical_days',
                'website_worker_concurrency'
            )
            ORDER BY setting_key
            SQL
        );
        $settings = [];
        foreach ($statement?->fetchAll() ?? [] as $row) {
            try {
                $settings[$row['setting_key']] = json_decode(
                    (string) $row['setting_value'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                continue;
            }
        }
        $appSettings = new AppSettingsRepository($this->pdo);
        $servers = $this->pdo->query(
            'SELECT id, name, address, is_active FROM servers ORDER BY name, id'
        )?->fetchAll() ?: [];
        foreach ($servers as &$server) {
            $server['id'] = (int) $server['id'];
            $server['is_active'] = filter_var($server['is_active'] ?? false, FILTER_VALIDATE_BOOL);
        }
        unset($server);
        $selectedHostId = $appSettings->get(SystemHealthService::HOST_SETTING);

        return $this->twig->render($response, 'admin/defaults.twig', [
            'title' => $this->translator->trans('settings.defaults.title'),
            'settings' => $settings,
            'servers' => $servers,
            'selected_host_id' => is_int($selectedHostId) ? $selectedHostId : null,
            'connectivity_settings' => (new ConnectivitySettingsService($appSettings))->current(),
        ]);
    }

    /** @param array<string, string> $args */
    public function saveDefaultSettings(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        try {
            $settings = $this->validatedDefaults($body);
        } catch (InvalidArgumentException $exception) {
            $this->flash($exception->getMessage(), 'error');
            return $this->redirect($response, '/admin/defaults');
        }

        $ownsTransaction = $this->beginTransaction('admin_save_defaults');
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO app_settings (setting_key, setting_value)
                 VALUES (:key, CAST(:value AS jsonb))
                 ON CONFLICT (setting_key) DO UPDATE
                 SET setting_value = EXCLUDED.setting_value'
            );
            foreach ($settings as $key => $value) {
                $statement->execute([
                    'key' => $key,
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                ]);
            }
            $this->commitTransaction($ownsTransaction, 'admin_save_defaults');
            $this->flashKey('admin.defaults.saved', 'success');
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'admin_save_defaults');
            $this->flashKey('admin.defaults.save_failed', 'error');
        }
        return $this->redirect($response, '/admin/defaults');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{user_id:?int,username:string,email:?string,password:string,role:'admin'|'operator'|'user',telegram_chat_id:?string,email_for_alerts:?string}
     */
    private function validatedUserInput(array $body): array
    {
        $userId = ($body['user_id'] ?? '') === '' ? null : $this->positiveInteger($body['user_id'] ?? null);
        if (($body['user_id'] ?? '') !== '' && $userId === null) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.not_found'));
        }
        $username = trim((string) ($body['username'] ?? ''));
        if (preg_match('/^[\p{L}\p{N}_.-]{3,80}$/u', $username) !== 1) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.validation.username'));
        }
        $email = $this->optionalString($body['email'] ?? null, 254);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.validation.email'));
        }
        $password = (string) ($body['password'] ?? '');
        if (strlen($password) > 1024) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.validation.password_long'));
        }
        if ($userId === null && strlen($password) < 12) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.validation.password_short'));
        }
        if ($userId !== null && $password !== '' && strlen($password) < 12) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.validation.new_password_short'));
        }
        $role = $body['role'] ?? 'user';
        if (!is_string($role) || !in_array($role, ['admin', 'operator', 'user'], true)) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.validation.role'));
        }
        $telegramChatId = $this->optionalString($body['telegram_chat_id'] ?? null, 100);
        $emailForAlerts = $this->optionalString($body['email_for_alerts'] ?? null, 254);
        if ($emailForAlerts !== null && filter_var($emailForAlerts, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.validation.alert_email'));
        }
        return [
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'telegram_chat_id' => $telegramChatId,
            'email_for_alerts' => $emailForAlerts,
        ];
    }

    /** @param array{username:string,email:?string,password:string,role:'admin'|'operator'|'user'} $input */
    private function createUser(array $input): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role)
             VALUES (:username, :email, :password_hash, :role)
             RETURNING id'
        );
        $statement->execute([
            'username' => $input['username'],
            'email' => $input['email'],
            'password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
            'role' => $input['role'],
        ]);
        return (int) $statement->fetchColumn();
    }

    /** @param array{user_id:int,username:string,email:?string,password:string,role:'admin'|'operator'|'user'} $input */
    private function updateUser(array $input): void
    {
        $lookup = $this->pdo->prepare('SELECT role FROM users WHERE id = :id FOR UPDATE');
        $lookup->execute(['id' => $input['user_id']]);
        $currentRole = $lookup->fetchColumn();
        if ($currentRole === false) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.not_found'));
        }
        if ($input['user_id'] === (int) ($_SESSION['user_id'] ?? 0) && $input['role'] !== 'admin') {
            throw new InvalidArgumentException($this->translator->trans('admin.user.validation.demote_self'));
        }
        if ($currentRole === 'admin' && $input['role'] !== 'admin' && $this->administratorCount() <= 1) {
            throw new InvalidArgumentException($this->translator->trans('admin.user.validation.demote_last_admin'));
        }
        if ($input['password'] === '') {
            $statement = $this->pdo->prepare(
                'UPDATE users SET username = :username, email = :email, role = :role WHERE id = :id'
            );
            $statement->execute([
                'id' => $input['user_id'],
                'username' => $input['username'],
                'email' => $input['email'],
                'role' => $input['role'],
            ]);
            return;
        }
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET username = :username, email = :email, password_hash = :password_hash, role = :role
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $input['user_id'],
            'username' => $input['username'],
            'email' => $input['email'],
            'password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
            'role' => $input['role'],
        ]);
    }

    private function saveUserNotifications(int $userId, ?string $telegramChatId, ?string $emailForAlerts): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_notification_settings (user_id, telegram_chat_id, email_for_alerts)
             VALUES (:user_id, :telegram_chat_id, :email_for_alerts)
             ON CONFLICT (user_id) DO UPDATE
             SET telegram_chat_id = EXCLUDED.telegram_chat_id,
                 email_for_alerts = EXCLUDED.email_for_alerts,
                 updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'user_id' => $userId,
            'telegram_chat_id' => $telegramChatId,
            'email_for_alerts' => $emailForAlerts,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, int|float>
     */
    private function validatedDefaults(array $body): array
    {
        $offlineTimeout = filter_var(
            $body['default_offline_timeout'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 30, 'max_range' => 86400]]
        );
        $duration = filter_var(
            $body['default_duration_seconds'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 86400]]
        );
        $recovery = filter_var(
            $body['default_recovery_duration_seconds'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 86400]]
        );
        $warning = $this->boundedFloat($body['default_warning_threshold'] ?? null, 0, 100);
        $critical = $this->boundedFloat($body['default_critical_threshold'] ?? null, 0, 100);
        if ($offlineTimeout === false || $duration === false || $recovery === false || $warning === null || $critical === null) {
            throw new InvalidArgumentException($this->translator->trans('admin.defaults.validation.range'));
        }
        if ($critical < $warning) {
            throw new InvalidArgumentException($this->translator->trans('admin.defaults.validation.threshold_order'));
        }
        $websiteInterval = $this->boundedInteger(
            $body['website_default_interval_seconds'] ?? null,
            10,
            86400
        );
        $websiteTimeout = $this->boundedInteger(
            $body['website_http_timeout_seconds'] ?? null,
            1,
            60
        );
        $tlsWarning = $this->boundedInteger(
            $body['website_tls_warning_days'] ?? null,
            1,
            3650
        );
        $tlsCritical = $this->boundedInteger(
            $body['website_tls_critical_days'] ?? null,
            0,
            3650
        );
        $domainWarning = $this->boundedInteger(
            $body['website_domain_warning_days'] ?? null,
            1,
            3650
        );
        $domainCritical = $this->boundedInteger(
            $body['website_domain_critical_days'] ?? null,
            0,
            3650
        );
        $workerConcurrency = $this->boundedInteger(
            $body['website_worker_concurrency'] ?? null,
            1,
            50
        );
        if ($websiteInterval === null || $websiteTimeout === null
            || $tlsWarning === null || $tlsCritical === null
            || $domainWarning === null || $domainCritical === null
            || $workerConcurrency === null) {
            throw new InvalidArgumentException($this->translator->trans('admin.defaults.validation.range'));
        }
        if ($tlsCritical > $tlsWarning || $domainCritical > $domainWarning) {
            throw new InvalidArgumentException(
                $this->translator->trans('admin.defaults.validation.expiry_order')
            );
        }
        return [
            'default_offline_timeout' => $offlineTimeout,
            'default_warning_threshold' => $warning,
            'default_critical_threshold' => $critical,
            'default_duration_seconds' => $duration,
            'default_recovery_duration_seconds' => $recovery,
            'website_default_interval_seconds' => $websiteInterval,
            'website_http_timeout_seconds' => $websiteTimeout,
            'website_tls_warning_days' => $tlsWarning,
            'website_tls_critical_days' => $tlsCritical,
            'website_domain_warning_days' => $domainWarning,
            'website_domain_critical_days' => $domainCritical,
            'website_worker_concurrency' => $workerConcurrency,
        ];
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);

        return $number === false ? null : $number;
    }

    private function boundedFloat(mixed $value, float $minimum, float $maximum): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) && $number >= $minimum && $number <= $maximum ? $number : null;
    }

    private function administratorCount(): int
    {
        return (int) $this->pdo->query("SELECT count(*) FROM users WHERE role = 'admin'")->fetchColumn();
    }

    private function lockAdministration(): void
    {
        $this->pdo->query("SELECT pg_advisory_xact_lock(hashtext('mirvmon:administrators'))");
    }

    private function optionalString(mixed $value, int $maximum): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maximum) {
            throw new InvalidArgumentException($this->translator->trans('admin.validation.field_too_long'));
        }
        return $value;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $integer === false ? null : $integer;
    }

    private function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? null) === 'admin';
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

    /** @param array{statuses:list<string>,channel:?string,server_id:?int,from:?string,to:?string,error:?string} $filters */
    private function queueLocation(array $filters): string
    {
        $query = [];
        if ($filters['statuses'] !== []) {
            $query['status'] = $filters['statuses'];
        }
        if ($filters['channel'] !== null) {
            $query['channel'] = $filters['channel'];
        }
        if ($filters['server_id'] !== null) {
            $query['server'] = $filters['server_id'];
        }
        if ($filters['from'] !== null) {
            $query['from'] = substr($filters['from'], 0, 10);
        }
        if ($filters['to'] !== null) {
            $query['to'] = substr($filters['to'], 0, 10);
        }
        if ($filters['error'] !== null) {
            $query['error'] = $filters['error'];
        }
        return '/admin/notifications/queue' . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * @param array{statuses:list<string>,channel:?string,server_id:?int,from:?string,to:?string,error:?string} $filters
     * @return list<array{name:string,value:string|int}>
     */
    private function queueFilterFields(array $filters): array
    {
        $fields = [];
        foreach ($filters['statuses'] as $status) {
            $fields[] = ['name' => 'status[]', 'value' => $status];
        }
        foreach ([
            'channel' => $filters['channel'],
            'server' => $filters['server_id'],
            'from' => $filters['from'] === null ? null : substr($filters['from'], 0, 10),
            'to' => $filters['to'] === null ? null : substr($filters['to'], 0, 10),
            'error' => $filters['error'],
        ] as $name => $value) {
            if ($value !== null) {
                $fields[] = ['name' => $name, 'value' => $value];
            }
        }
        return $fields;
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    private function beginTransaction(string $savepoint): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            return true;
        }
        $this->pdo->exec('SAVEPOINT ' . $savepoint);
        return false;
    }

    private function commitTransaction(bool $ownsTransaction, string $savepoint): void
    {
        if ($ownsTransaction) {
            $this->pdo->commit();
            return;
        }
        $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    private function rollbackTransaction(bool $ownsTransaction, string $savepoint): void
    {
        if ($ownsTransaction) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return;
        }
        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }
}
