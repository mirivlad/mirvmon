<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Repositories\WorkerHeartbeatRepository;
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
        private readonly WorkerHeartbeatRepository $heartbeats
    ) {
    }

    /** @param array<string, string> $args */
    public function usersList(
        Request $request,
        Response $response,
        array $args
    ): Response {
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
            LEFT JOIN user_notification_settings AS settings
              ON settings.user_id = users.id
            ORDER BY users.created_at DESC, users.id DESC
            SQL
        )?->fetchAll() ?? [];

        return $this->twig->render($response, 'admin/users.twig', [
            'title' => 'Управление пользователями',
            'users' => $users,
        ]);
    }

    /** @param array<string, string> $args */
    public function saveUser(
        Request $request,
        Response $response,
        array $args
    ): Response {
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
                $message = sprintf(
                    'Пользователь «%s» создан',
                    $input['username']
                );
            } else {
                $this->updateUser($input);
                $userId = $input['user_id'];
                $message = sprintf(
                    'Пользователь «%s» обновлён',
                    $input['username']
                );
            }

            $this->saveUserNotifications(
                $userId,
                $input['telegram_chat_id'],
                $input['email_for_alerts']
            );
            $this->commitTransaction($ownsTransaction, 'admin_save_user');
            $this->flash($message, 'success');
        } catch (InvalidArgumentException $exception) {
            $this->rollbackTransaction($ownsTransaction, 'admin_save_user');
            $this->flash($exception->getMessage(), 'error');
        } catch (PDOException $exception) {
            $this->rollbackTransaction($ownsTransaction, 'admin_save_user');
            $message = $exception->getCode() === '23505'
                ? 'Пользователь с таким именем уже существует'
                : 'Не удалось сохранить пользователя';
            $this->flash($message, 'error');
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'admin_save_user');
            $this->flash('Не удалось сохранить пользователя', 'error');
        }

        return $this->redirect($response, '/admin/users');
    }

    /** @param array<string, string> $args */
    public function deleteUser(
        Request $request,
        Response $response,
        array $args
    ): Response {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }

        $userId = $this->positiveInteger($args['id'] ?? null);
        if ($userId === null) {
            $this->flash('Пользователь не найден', 'error');

            return $this->redirect($response, '/admin/users');
        }
        if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {
            $this->flash('Нельзя удалить собственную учётную запись', 'error');

            return $this->redirect($response, '/admin/users');
        }

        $ownsTransaction = $this->beginTransaction('admin_delete_user');
        try {
            $this->lockAdministration();
            $statement = $this->pdo->prepare(
                'SELECT username, role FROM users WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $userId]);
            $user = $statement->fetch();
            if (!is_array($user)) {
                throw new InvalidArgumentException('Пользователь не найден');
            }
            if (
                $user['role'] === 'admin'
                && $this->administratorCount() <= 1
            ) {
                throw new InvalidArgumentException(
                    'Нельзя удалить последнего администратора'
                );
            }

            $delete = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
            $delete->execute(['id' => $userId]);
            $this->commitTransaction($ownsTransaction, 'admin_delete_user');
            $this->flash(
                sprintf('Пользователь «%s» удалён', $user['username']),
                'success'
            );
        } catch (InvalidArgumentException $exception) {
            $this->rollbackTransaction($ownsTransaction, 'admin_delete_user');
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'admin_delete_user');
            $this->flash('Не удалось удалить пользователя', 'error');
        }

        return $this->redirect($response, '/admin/users');
    }

    /** @param array<string, string> $args */
    public function notificationSettings(
        Request $request,
        Response $response,
        array $args
    ): Response {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }

        return $this->twig->render($response, 'admin/notifications.twig', [
            'title' => 'Настройки уведомлений',
            'settings' => $this->notificationSettings->getPublic(),
            'queue' => $this->notificationOutbox->recent(20),
            'queue_counts' => $this->notificationOutbox->statusCounts(),
            'heartbeats' => $this->heartbeats->all(),
        ]);
    }

    /** @param array<string, string> $args */
    public function saveNotificationSettings(
        Request $request,
        Response $response,
        array $args
    ): Response {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }

        $body = $request->getParsedBody();
        try {
            $this->notificationSettings->save(is_array($body) ? $body : []);
            $this->flash('Настройки уведомлений сохранены', 'success');
        } catch (InvalidArgumentException $exception) {
            $this->flash(
                'Настройки не сохранены: ' . $exception->getMessage(),
                'error'
            );
        } catch (Throwable) {
            $this->flash('Не удалось сохранить настройки уведомлений', 'error');
        }

        return $this->redirect($response, '/admin/notifications');
    }

    /** @param array<string, string> $args */
    public function retryNotificationQueue(
        Request $request,
        Response $response,
        array $args
    ): Response {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }

        try {
            $requeued = $this->notificationOutbox->retryUndelivered();
            $this->flash(
                sprintf('Повторная отправка запланирована для записей: %d', $requeued),
                'success'
            );
        } catch (Throwable) {
            $this->flash('Не удалось перезапустить очередь уведомлений', 'error');
        }

        return $this->redirect($response, '/admin/notifications');
    }

    /** @param array<string, string> $args */
    public function testNotification(
        Request $request,
        Response $response,
        array $args
    ): Response {
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
                    'Включите хотя бы один канал уведомлений.'
                );
            }
            $this->flash(
                sprintf('Тест поставлен в очередь для каналов: %d', $queued),
                'success'
            );
        } catch (InvalidArgumentException $exception) {
            $this->flash(
                'Тест не поставлен в очередь: ' . $exception->getMessage(),
                'error'
            );
        } catch (Throwable) {
            $this->flash(
                'Не удалось поставить тестовое уведомление в очередь',
                'error'
            );
        }

        return $this->redirect($response, '/admin/notifications');
    }

    /** @param array<string, string> $args */
    public function defaultSettings(
        Request $request,
        Response $response,
        array $args
    ): Response {
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
                'default_recovery_duration_seconds'
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

        return $this->twig->render($response, 'admin/defaults.twig', [
            'title' => 'Параметры по умолчанию',
            'settings' => $settings,
        ]);
    }

    /** @param array<string, string> $args */
    public function saveDefaultSettings(
        Request $request,
        Response $response,
        array $args
    ): Response {
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
                <<<'SQL'
                INSERT INTO app_settings (setting_key, setting_value)
                VALUES (:key, CAST(:value AS jsonb))
                ON CONFLICT (setting_key) DO UPDATE
                SET setting_value = EXCLUDED.setting_value
                SQL
            );
            foreach ($settings as $key => $value) {
                $statement->execute([
                    'key' => $key,
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                ]);
            }
            $this->commitTransaction($ownsTransaction, 'admin_save_defaults');
            $this->flash('Параметры по умолчанию сохранены', 'success');
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'admin_save_defaults');
            $this->flash('Не удалось сохранить параметры', 'error');
        }

        return $this->redirect($response, '/admin/defaults');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{
     *     user_id: ?int,
     *     username: string,
     *     email: ?string,
     *     password: string,
     *     role: 'admin'|'user',
     *     telegram_chat_id: ?string,
     *     email_for_alerts: ?string
     * }
     */
    private function validatedUserInput(array $body): array
    {
        $userId = ($body['user_id'] ?? '') === ''
            ? null
            : $this->positiveInteger($body['user_id'] ?? null);
        if (($body['user_id'] ?? '') !== '' && $userId === null) {
            throw new InvalidArgumentException('Пользователь не найден');
        }

        $username = trim((string) ($body['username'] ?? ''));
        if (preg_match('/^[\p{L}\p{N}_.-]{3,80}$/u', $username) !== 1) {
            throw new InvalidArgumentException(
                'Имя пользователя должно содержать от 3 до 80 допустимых символов'
            );
        }

        $email = $this->optionalString($body['email'] ?? null, 254);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Укажите корректный email');
        }

        $password = (string) ($body['password'] ?? '');
        if (strlen($password) > 1024) {
            throw new InvalidArgumentException(
                'Пароль не должен превышать 1024 символа'
            );
        }
        if ($userId === null && strlen($password) < 12) {
            throw new InvalidArgumentException(
                'Пароль должен содержать не менее 12 символов'
            );
        }
        if ($userId !== null && $password !== '' && strlen($password) < 12) {
            throw new InvalidArgumentException(
                'Новый пароль должен содержать не менее 12 символов'
            );
        }

        $role = $body['role'] ?? 'user';
        if (!is_string($role) || !in_array($role, ['admin', 'user'], true)) {
            throw new InvalidArgumentException('Недопустимая роль пользователя');
        }

        $telegramChatId = $this->optionalString(
            $body['telegram_chat_id'] ?? null,
            100
        );
        $emailForAlerts = $this->optionalString(
            $body['email_for_alerts'] ?? null,
            254
        );
        if (
            $emailForAlerts !== null
            && filter_var($emailForAlerts, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                'Укажите корректный email для алертов'
            );
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

    /**
     * @param array{
     *     username: string,
     *     email: ?string,
     *     password: string,
     *     role: 'admin'|'user'
     * } $input
     */
    private function createUser(array $input): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO users (username, email, password_hash, role)
            VALUES (:username, :email, :password_hash, :role)
            RETURNING id
            SQL
        );
        $statement->execute([
            'username' => $input['username'],
            'email' => $input['email'],
            'password_hash' => password_hash(
                $input['password'],
                PASSWORD_DEFAULT
            ),
            'role' => $input['role'],
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{
     *     user_id: int,
     *     username: string,
     *     email: ?string,
     *     password: string,
     *     role: 'admin'|'user'
     * } $input
     */
    private function updateUser(array $input): void
    {
        $lookup = $this->pdo->prepare(
            'SELECT role FROM users WHERE id = :id FOR UPDATE'
        );
        $lookup->execute(['id' => $input['user_id']]);
        $currentRole = $lookup->fetchColumn();
        if ($currentRole === false) {
            throw new InvalidArgumentException('Пользователь не найден');
        }
        if (
            $input['user_id'] === (int) ($_SESSION['user_id'] ?? 0)
            && $input['role'] !== 'admin'
        ) {
            throw new InvalidArgumentException(
                'Нельзя снять роль администратора с собственной учётной записи'
            );
        }
        if (
            $currentRole === 'admin'
            && $input['role'] !== 'admin'
            && $this->administratorCount() <= 1
        ) {
            throw new InvalidArgumentException(
                'Нельзя изменить роль последнего администратора'
            );
        }

        if ($input['password'] === '') {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                UPDATE users
                SET username = :username, email = :email, role = :role
                WHERE id = :id
                SQL
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
            <<<'SQL'
            UPDATE users
            SET
                username = :username,
                email = :email,
                password_hash = :password_hash,
                role = :role
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'id' => $input['user_id'],
            'username' => $input['username'],
            'email' => $input['email'],
            'password_hash' => password_hash(
                $input['password'],
                PASSWORD_DEFAULT
            ),
            'role' => $input['role'],
        ]);
    }

    private function saveUserNotifications(
        int $userId,
        ?string $telegramChatId,
        ?string $emailForAlerts
    ): void {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO user_notification_settings (
                user_id,
                telegram_chat_id,
                email_for_alerts
            )
            VALUES (:user_id, :telegram_chat_id, :email_for_alerts)
            ON CONFLICT (user_id) DO UPDATE
            SET
                telegram_chat_id = EXCLUDED.telegram_chat_id,
                email_for_alerts = EXCLUDED.email_for_alerts,
                updated_at = CURRENT_TIMESTAMP
            SQL
        );
        $statement->execute([
            'user_id' => $userId,
            'telegram_chat_id' => $telegramChatId,
            'email_for_alerts' => $emailForAlerts,
        ]);
    }

    /** @param array<string, mixed> $body
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
        $warning = $this->boundedFloat(
            $body['default_warning_threshold'] ?? null,
            0,
            100
        );
        $critical = $this->boundedFloat(
            $body['default_critical_threshold'] ?? null,
            0,
            100
        );
        if (
            $offlineTimeout === false
            || $duration === false
            || $recovery === false
            || $warning === null
            || $critical === null
        ) {
            throw new InvalidArgumentException(
                'Проверьте диапазоны параметров по умолчанию'
            );
        }
        if ($critical < $warning) {
            throw new InvalidArgumentException(
                'Critical threshold должен быть не ниже warning threshold'
            );
        }

        return [
            'default_offline_timeout' => $offlineTimeout,
            'default_warning_threshold' => $warning,
            'default_critical_threshold' => $critical,
            'default_duration_seconds' => $duration,
            'default_recovery_duration_seconds' => $recovery,
        ];
    }

    private function boundedFloat(
        mixed $value,
        float $minimum,
        float $maximum
    ): ?float {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= $minimum && $number <= $maximum
            ? $number
            : null;
    }

    private function administratorCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT count(*) FROM users WHERE role = 'admin'"
        )->fetchColumn();
    }

    private function lockAdministration(): void
    {
        $this->pdo->query(
            "SELECT pg_advisory_xact_lock(hashtext('mirvmon:administrators'))"
        );
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
            throw new InvalidArgumentException(
                'Значение одного из полей превышает допустимую длину'
            );
        }

        return $value;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $integer === false ? null : $integer;
    }

    private function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? null) === 'admin';
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

    private function beginTransaction(string $savepoint): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            return true;
        }

        $this->pdo->exec('SAVEPOINT ' . $savepoint);
        return false;
    }

    private function commitTransaction(
        bool $ownsTransaction,
        string $savepoint
    ): void {
        if ($ownsTransaction) {
            $this->pdo->commit();
            return;
        }

        $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    private function rollbackTransaction(
        bool $ownsTransaction,
        string $savepoint
    ): void {
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
