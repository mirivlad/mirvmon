<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Throwable;

final class SetupController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly string $setupToken
    ) {
    }

    /** @param array<string, string> $arguments */
    public function form(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments
    ): ResponseInterface {
        if ($this->userCount() > 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        return $this->twig->render($response, 'setup.twig', [
            'title' => 'Первоначальная настройка',
        ]);
    }

    /** @param array<string, string> $arguments */
    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments
    ): ResponseInterface {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $token = (string) ($body['setup_token'] ?? '');
        $username = trim((string) ($body['username'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirmation = (string) ($body['password_confirmation'] ?? '');

        $error = $this->validate($token, $username, $email, $password, $confirmation);
        if ($error !== null) {
            $_SESSION['flash_message'] = $error;
            $_SESSION['flash_type'] = 'error';

            return $response->withHeader('Location', '/setup')->withStatus(302);
        }

        try {
            $this->pdo->beginTransaction();
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $this->pdo->query("SELECT pg_advisory_xact_lock(hashtext('mirvmon:first-admin'))");
            }
            if ($this->userCount() > 0) {
                $this->pdo->rollBack();

                return $response->withHeader('Location', '/login')->withStatus(302);
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO users(username, email, password_hash, role, is_active)
                 VALUES (:username, :email, :password_hash, :role, :is_active)'
            );
            $statement->bindValue(':username', $username);
            $statement->bindValue(':email', $email === '' ? null : $email);
            $statement->bindValue(':password_hash', password_hash($password, PASSWORD_DEFAULT));
            $statement->bindValue(':role', 'admin');
            $statement->bindValue(':is_active', true, PDO::PARAM_BOOL);
            $statement->execute();

            $lookup = $this->pdo->prepare(
                'SELECT id, username, role FROM users WHERE username = :username'
            );
            $lookup->execute([':username' => $username]);
            $user = $lookup->fetch();
            $this->pdo->commit();

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = (string) $user['username'];
            $_SESSION['role'] = (string) $user['role'];
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);

            return $response->withHeader('Location', '/')->withStatus(302);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $_SESSION['flash_message'] = 'Не удалось создать администратора';
            $_SESSION['flash_type'] = 'error';

            return $response->withHeader('Location', '/setup')->withStatus(302);
        }
    }

    private function validate(
        string $token,
        string $username,
        string $email,
        string $password,
        string $confirmation
    ): ?string {
        if ($this->setupToken === '' || !hash_equals($this->setupToken, $token)) {
            return 'Неверный токен первоначальной настройки';
        }
        if (preg_match('/^[\p{L}\p{N}_.-]{3,80}$/u', $username) !== 1) {
            return 'Имя пользователя должно содержать от 3 до 80 допустимых символов';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Укажите корректный email';
        }
        if (strlen($password) < 12) {
            return 'Пароль должен содержать не менее 12 символов';
        }
        if (!hash_equals($password, $confirmation)) {
            return 'Пароли не совпадают';
        }

        return null;
    }

    private function userCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
