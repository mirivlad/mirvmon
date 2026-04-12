<?php
// src/Controllers/AdminController.php

namespace App\Controllers;

use App\Models\Model;
use App\Services\NotificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AdminController extends Model
{
    private $twig;
    private $notificationService;

    public function __construct(Twig $twig)
    {
        parent::__construct();
        $this->twig = $twig;
        $this->notificationService = new NotificationService();
    }

    // ==================== ПОЛЬЗОВАТЕЛИ ====================

    public function usersList(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        
        $stmt = $this->pdo->prepare("
            SELECT u.id, u.username, u.email, u.role, u.created_at,
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

        $settings = $this->notificationService->getSettings();

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
        $this->notificationService->saveSettings($data);

        $_SESSION['flash_message'] = 'Настройки уведомлений сохранены';
        $_SESSION['flash_type'] = 'success';

        return $response->withHeader('Location', '/admin/notifications')->withStatus(302);
    }

    public function testNotification(Request $request, Response $response, $args)
    {
        if ($_SESSION['role'] !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $settings = $this->notificationService->getSettings();
        $results = $this->notificationService->sendTestNotification(
            $settings['smtp_from_email'],
            $settings['telegram_chat_id']
        );

        $status = 'success';
        $messages = [];
        foreach ($results as $channel => $result) {
            if ($result['success']) {
                $messages[] = "{$channel}: ✅ " . $result['message'];
            } else {
                $messages[] = "{$channel}: ❌ " . $result['error'];
                $status = 'error';
            }
        }

        $_SESSION['flash_message'] = implode("\n", $messages);
        $_SESSION['flash_type'] = $status;

        return $response->withHeader('Location', '/admin/notifications')->withStatus(302);
    }
}
