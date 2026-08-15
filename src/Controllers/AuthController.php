<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\Middlewares\SessionSecurityMiddleware;
use App\Middlewares\TrustedProxyMiddleware;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class AuthController
{
    private const DUMMY_PASSWORD_HASH =
        '$2y$12$y7EG5PylhK.PzbLP5J6zCe0s3fr1FqzL4vC5ZQhVqEcYmUWKNfndG';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly string $applicationKey,
        private readonly Translator $translator
    ) {
    }

    /** @param array<string, string> $arguments */
    public function form(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        if (isset($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        if ($this->userCount() === 0) {
            return $response->withHeader('Location', '/setup')->withStatus(302);
        }

        return $this->twig->render($response, 'login.twig', [
            'title' => $this->translator->trans('auth.login.title'),
        ]);
    }

    /** @param array<string, string> $arguments */
    public function login(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        if ($this->userCount() === 0) {
            return $response->withHeader('Location', '/setup')->withStatus(302);
        }

        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $rememberMe = (string) ($body['remember_me'] ?? '') === '1';
        $sourceHash = $this->sourceHash($request);
        $attemptUsername = strlen($username) <= 80 ? $username : '__invalid__';

        if ($this->isRateLimited($attemptUsername, $sourceHash)) {
            $this->invalidCredentials();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash, role, is_active
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute([':username' => $attemptUsername]);
        $user = $statement->fetch();

        $passwordHash = is_array($user)
            ? (string) $user['password_hash']
            : self::DUMMY_PASSWORD_HASH;
        $passwordValid = password_verify($password, $passwordHash);
        $valid = is_array($user) && (bool) $user['is_active'] && $passwordValid;
        if (!$valid) {
            $this->recordAttempt($attemptUsername, $sourceHash, false);
            $this->invalidCredentials();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $this->recordAttempt($attemptUsername, $sourceHash, true);
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->pdo->prepare(
                'UPDATE users SET password_hash = :password_hash WHERE id = :id'
            );
            $rehash->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $user['id'],
            ]);
        }
        $update = $this->pdo->prepare(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $update->execute([':id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = (string) $user['username'];
        $_SESSION['role'] = (string) $user['role'];
        if ($rememberMe) {
            $_SESSION['_remember_until'] = time() + SessionSecurityMiddleware::REMEMBER_ME_SECONDS;
        } else {
            unset($_SESSION['_remember_until']);
        }
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);

        return $response->withHeader('Location', '/')->withStatus(302);
    }

    /** @param array<string, string> $arguments */
    public function logout(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $_SESSION['_destroyed'] = true;
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    private function invalidCredentials(): void
    {
        $_SESSION['flash_message'] = $this->translator->trans('auth.login.invalid');
        $_SESSION['flash_type'] = 'error';
    }

    private function userCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    private function sourceHash(ServerRequestInterface $request): string
    {
        $address = (string) $request->getAttribute(
            TrustedProxyMiddleware::CLIENT_IP_ATTRIBUTE,
            $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'
        );
        return hash_hmac('sha256', $address, $this->applicationKey);
    }

    private function isRateLimited(string $username, string $sourceHash): bool
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-15 minutes')
            ->format('Y-m-d H:i:sP');
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE username = :username
               AND source_hash = :source_hash
               AND succeeded = :succeeded
               AND attempted_at >= :cutoff'
        );
        $statement->bindValue(':username', $username);
        $statement->bindValue(':source_hash', $sourceHash);
        $statement->bindValue(':succeeded', false, PDO::PARAM_BOOL);
        $statement->bindValue(':cutoff', $cutoff);
        $statement->execute();
        return (int) $statement->fetchColumn() >= 5;
    }

    private function recordAttempt(string $username, string $sourceHash, bool $succeeded): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts(username, source_hash, succeeded)
             VALUES (:username, :source_hash, :succeeded)'
        );
        $statement->bindValue(':username', $username);
        $statement->bindValue(':source_hash', $sourceHash);
        $statement->bindValue(':succeeded', $succeeded, PDO::PARAM_BOOL);
        $statement->execute();
    }
}
