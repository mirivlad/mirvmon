<?php
// src/Controllers/AdminController.php

namespace App\Controllers;

use App\Models\Model;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

class AdminController extends Model
{
    public function __construct(
        private readonly Twig $twig,
        private readonly NotificationSettingsRepository $notificationSettings,
        private readonly NotificationOutboxRepository $notificationOutbox
    )
    {
        parent::__construct();
    }

    // ==================== ПОЛЬЗОВАТЕЛИ ====================

    public function usersList(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT u.id, u.username, u.email, u.role, u.created_at,
                   uns.telegram_chat_id, uns.email_for_alerts
            FROM users u
            LEFT JOIN user_notification_settings uns ON u.id = uns.user_id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();

        return $this->twig->render($response, 'admin/users.twig', [
            'title' => 'Управление пользователями',
            'users' => $users
        ]);
    }

    public function saveUser(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = $request->getParsedBody();
        $userId = $data['user_id'] ?? null;
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $role = in_array($data['role'], ['admin', 'user']) ? $data['role'] : 'user';
        $telegramChatId = trim($data['telegram_chat_id'] ?? '');
        $emailForAlerts = trim($data['email_for_alerts'] ?? '');

        if (empty($username)) {
            $_SESSION['flash_message'] = 'Имя пользователя обязательно';
            $_SESSION['flash_type'] = 'error';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        try {
            $this->pdo->beginTransaction();

            if ($userId) {
                // Редактирование
                if (!empty($password)) {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $this->pdo->prepare("
                        UPDATE users SET username = :username, email = :email, password_hash = :password_hash, role = :role
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':username' => $username,
                        ':email' => $email,
                        ':password_hash' => $passwordHash,
                        ':role' => $role,
                        ':id' => $userId
                    ]);
                } else {
                    $stmt = $this->pdo->prepare("
                        UPDATE users SET username = :username, email = :email, role = :role WHERE id = :id
                    ");
                    $stmt->execute([
                        ':username' => $username,
                        ':email' => $email,
                        ':role' => $role,
                        ':id' => $userId
                    ]);
                }

                // Обновляем настройки уведомлений
                $stmt = $this->pdo->prepare("
                    INSERT INTO user_notification_settings (user_id, telegram_chat_id, email_for_alerts)
                    VALUES (:user_id, :telegram_chat_id, :email_for_alerts)
                    ON DUPLICATE KEY UPDATE
                        telegram_chat_id = VALUES(telegram_chat_id),
                        email_for_alerts = VALUES(email_for_alerts)
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':telegram_chat_id' => $telegramChatId,
                    ':email_for_alerts' => $emailForAlerts
                ]);

                $message = "Пользователь «{$username}» обновлён";
            } else {
                // Создание
                if (empty($password)) {
                    $_SESSION['flash_message'] = 'Пароль обязателен при создании пользователя';
                    $_SESSION['flash_type'] = 'error';
                    return $response->withHeader('Location', '/admin/users')->withStatus(302);
                }

                // Проверяем уникальность имени
                $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = :username");
                $stmt->execute([':username' => $username]);
                if ($stmt->fetch()) {
                    $_SESSION['flash_message'] = "Пользователь «{$username}» уже существует";
                    $_SESSION['flash_type'] = 'error';
                    return $response->withHeader('Location', '/admin/users')->withStatus(302);
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $this->pdo->prepare("
                    INSERT INTO users (username, email, password_hash, role)
                    VALUES (:username, :email, :password_hash, :role)
                ");
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':password_hash' => $passwordHash,
                    ':role' => $role
                ]);
                $newUserId = $this->pdo->lastInsertId();

                // Создаём настройки уведомлений
                $stmt = $this->pdo->prepare("
                    INSERT INTO user_notification_settings (user_id, telegram_chat_id, email_for_alerts)
                    VALUES (:user_id, :telegram_chat_id, :email_for_alerts)
                ");
                $stmt->execute([
                    ':user_id' => $newUserId,
                    ':telegram_chat_id' => $telegramChatId,
                    ':email_for_alerts' => $emailForAlerts
                ]);

                $message = "Пользователь «{$username}» создан";
            }

            $this->pdo->commit();
            $_SESSION['flash_message'] = $message;
            $_SESSION['flash_type'] = 'success';

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $_SESSION['flash_message'] = 'Ошибка: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    public function deleteUser(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $userId = $args['id'];

        // Не даём удалить себя
        if ($userId == $_SESSION['user_id']) {
            $_SESSION['flash_message'] = 'Нельзя удалить себя';
            $_SESSION['flash_type'] = 'error';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        try {
            $stmt = $this->pdo->prepare("SELECT username FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if (!$user) {
                $_SESSION['flash_message'] = 'Пользователь не найден';
                $_SESSION['flash_type'] = 'error';
                return $response->withHeader('Location', '/admin/users')->withStatus(302);
            }

            $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);

            $_SESSION['flash_message'] = "Пользователь «{$user['username']}» удалён";
            $_SESSION['flash_type'] = 'success';

        } catch (\Exception $e) {
            $_SESSION['flash_message'] = 'Ошибка удаления: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    // ==================== НАСТРОЙКИ УВЕДОМЛЕНИЙ ====================

    public function notificationSettings(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $settings = $this->notificationSettings->getPublic();

        return $this->twig->render($response, 'admin/notifications.twig', [
            'title' => 'Настройки уведомлений',
            'settings' => $settings
        ]);
    }

    public function saveNotificationSettings(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = $request->getParsedBody();
        try {
            $this->notificationSettings->save(is_array($data) ? $data : []);
            $_SESSION['flash_message'] = 'Настройки уведомлений сохранены';
            $_SESSION['flash_type'] = 'success';
        } catch (InvalidArgumentException $exception) {
            $_SESSION['flash_message'] = 'Настройки не сохранены: '
                . $exception->getMessage();
            $_SESSION['flash_type'] = 'error';
        } catch (Throwable) {
            $_SESSION['flash_message'] = 'Не удалось сохранить настройки уведомлений';
            $_SESSION['flash_type'] = 'error';
        }

        return $response->withHeader('Location', '/admin/notifications')->withStatus(302);
    }

    public function testNotification(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = $request->getParsedBody();
        try {
            $this->notificationSettings->save(is_array($data) ? $data : []);
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
            $_SESSION['flash_message'] = sprintf(
                'Тест поставлен в очередь для каналов: %d',
                $queued
            );
            $_SESSION['flash_type'] = 'success';
        } catch (InvalidArgumentException $exception) {
            $_SESSION['flash_message'] = 'Тест не поставлен в очередь: '
                . $exception->getMessage();
            $_SESSION['flash_type'] = 'error';
        } catch (Throwable) {
            $_SESSION['flash_message'] =
                'Не удалось поставить тестовое уведомление в очередь';
            $_SESSION['flash_type'] = 'error';
        }

        return $response->withHeader('Location', '/admin/notifications')->withStatus(302);
    }

    // ==================== ДЕФОЛТНЫЕ ПАРАМЕТРЫ ====================

    public function defaultSettings(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM default_settings ORDER BY id");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $this->twig->render($response, 'admin/defaults.twig', [
            'title' => 'Дефолтные параметры',
            'settings' => $settings
        ]);
    }

    public function saveDefaultSettings(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = $request->getParsedBody();

        $settingsToUpdate = [
            'offline_check_interval' => (int)($data['offline_check_interval'] ?? 60),
            'default_offline_timeout' => (int)($data['default_offline_timeout'] ?? 300),
            'default_warning_threshold' => (float)($data['default_warning_threshold'] ?? 70),
            'default_critical_threshold' => (float)($data['default_critical_threshold'] ?? 90),
            'default_duration' => (int)($data['default_duration'] ?? 0),
        ];

        foreach ($settingsToUpdate as $key => $value) {
            $stmt = $this->pdo->prepare("
                INSERT INTO default_settings (setting_key, setting_value)
                VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE setting_value = :value2
            ");
            $stmt->execute([':key' => $key, ':value' => $value, ':value2' => $value]);
        }

        $_SESSION['flash_message'] = 'Дефолтные параметры сохранены';
        $_SESSION['flash_type'] = 'success';

        return $response->withHeader('Location', '/admin/defaults')->withStatus(302);
    }

    public function getDefaultSetting($key, $default = null)
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM default_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    }
}
