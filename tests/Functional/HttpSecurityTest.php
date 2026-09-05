<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\AppFactory;
use App\Application\Bootstrap;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HttpSecurityTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private string $sessionDirectory;

    protected function setUp(): void
    {
        $this->sessionDirectory = sys_get_temp_dir() . '/mirvmon-session-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->sessionDirectory));
        ini_set('session.save_path', $this->sessionDirectory);

        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                last_login_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                source_hash TEXT NOT NULL,
                succeeded INTEGER NOT NULL,
                attempted_at TEXT DEFAULT CURRENT_TIMESTAMP
            );"
        );

        $container = Bootstrap::createContainer([
            'app_env' => 'test',
            'app_debug' => false,
            'app_key' => base64_encode(str_repeat('k', 32)),
            'setup_token' => str_repeat('s', 32),
            'timezone' => 'UTC',
            'trusted_proxies' => ['10.0.0.0/8'],
            'session_name' => 'mirvmon_test',
            'session_secure' => false,
            'max_request_bytes' => 1024,
            'templates_path' => dirname(__DIR__, 2) . '/templates',
            'twig_cache' => false,
        ], $this->pdo);

        $this->app = AppFactory::create($container);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function testUnknownRouteReturns404WithSecurityHeaders(): void
    {
        $response = $this->app->handle($this->request('GET', '/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringNotContainsString('RuntimeException', (string) $response->getBody());
    }

    public function testInlineScriptsUsePerResponseCspNonce(): void
    {
        $this->app->get('/_nonce-test', static function (
            \Psr\Http\Message\ServerRequestInterface $request,
            \Psr\Http\Message\ResponseInterface $response
        ): \Psr\Http\Message\ResponseInterface {
            $response->getBody()->write(
                '<script nonce="__MIRVMON_CSP_NONCE__">window.test = true;</script>'
            );

            return $response;
        });

        $response = $this->app->handle($this->request('GET', '/_nonce-test'));
        $policy = $response->getHeaderLine('Content-Security-Policy');
        $body = (string) $response->getBody();

        self::assertMatchesRegularExpression(
            "/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+'/",
            $policy
        );
        self::assertStringNotContainsString(
            "script-src 'self' 'unsafe-inline'",
            $policy
        );
        self::assertStringNotContainsString('__MIRVMON_CSP_NONCE__', $body);
        self::assertMatchesRegularExpression(
            '/<script nonce="[A-Za-z0-9+\\/=]+">/',
            $body
        );

        $secondResponse = $this->app->handle($this->request('GET', '/_nonce-test'));
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $firstNonce);
        preg_match(
            "/script-src 'self' 'nonce-([^']+)'/",
            $secondResponse->getHeaderLine('Content-Security-Policy'),
            $secondNonce
        );
        self::assertArrayHasKey(1, $firstNonce);
        self::assertArrayHasKey(1, $secondNonce);
        self::assertNotSame($firstNonce[1], $secondNonce[1]);
    }

    public function testFirstRunRedirectsToTokenProtectedSetup(): void
    {
        $login = $this->app->handle($this->request('GET', '/login'));
        self::assertSame(302, $login->getStatusCode());
        self::assertSame('/setup', $login->getHeaderLine('Location'));

        $form = $this->app->handle($this->request('GET', '/setup'));
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('name="setup_token"', (string) $form->getBody());
        self::assertStringNotContainsString(str_repeat('s', 32), (string) $form->getBody());
    }

    public function testSetupCreatesOnlyTheFirstAdministratorAndRotatesSession(): void
    {
        $form = $this->app->handle($this->request('GET', '/setup'));
        $cookie = $this->cookiePair($form->getHeaderLine('Set-Cookie'));
        [$nameKey, $name, $valueKey, $value] = $this->csrfFields((string) $form->getBody());

        $response = $this->app->handle(
            $this->request('POST', '/setup')
                ->withCookieParams(['mirvmon_test' => $cookie[1]])
                ->withParsedBody([
                    $nameKey => $name,
                    $valueKey => $value,
                    'setup_token' => str_repeat('s', 32),
                    'username' => 'first-admin',
                    'email' => 'admin@example.test',
                    'password' => 'correct horse battery staple',
                    'password_confirmation' => 'correct horse battery staple',
                ])
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertNotSame(
            $cookie[1],
            $this->cookiePair($response->getHeaderLine('Set-Cookie'))[1]
        );
        self::assertSame(
            ['first-admin', 'admin'],
            $this->pdo->query('SELECT username, role FROM users')->fetch(PDO::FETCH_NUM)
        );

        $secondSetup = $this->app->handle($this->request('GET', '/setup'));
        self::assertSame(302, $secondSetup->getStatusCode());
        self::assertSame('/login', $secondSetup->getHeaderLine('Location'));
    }

    public function testApiExceptionReturnsStableJson500(): void
    {
        $this->app->get('/api/_boom', static function (): never {
            throw new RuntimeException('secret detail');
        });

        $response = $this->app->handle($this->request('GET', '/api/_boom'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('secret detail', (string) $response->getBody());
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{32}$/',
            $response->getHeaderLine('X-Request-ID')
        );
    }

    public function testProcessEndpointRequiresAuthentication(): void
    {
        $response = $this->app->handle(
            $this->request('GET', '/api/v1/agent/1/processes')
                ->withHeader('Accept', 'application/json')
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testViewerCannotOpenAdministrativeServerCreation(): void
    {
        [, $sessionId] = $this->authenticatedSession('user');

        $response = $this->app->handle(
            $this->request('GET', '/servers/create')
                ->withCookieParams(['mirvmon_test' => $sessionId])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDeletedUserSessionIsRejected(): void
    {
        [, $sessionId] = $this->authenticatedSession();
        $this->pdo->exec('DELETE FROM users WHERE id = 1');

        $response = $this->app->handle(
            $this->request('GET', '/api/v1/agent/1/processes')
                ->withCookieParams(['mirvmon_test' => $sessionId])
                ->withHeader('Accept', 'application/json')
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testLogoutIsPostOnlyAndRejectsMissingCsrfToken(): void
    {
        [, $sessionId] = $this->authenticatedSession();

        $getResponse = $this->app->handle(
            $this->request('GET', '/logout')
                ->withCookieParams(['mirvmon_test' => $sessionId])
        );
        self::assertSame(405, $getResponse->getStatusCode());

        $postResponse = $this->app->handle(
            $this->request('POST', '/logout')
                ->withCookieParams(['mirvmon_test' => $sessionId])
                ->withParsedBody([])
        );
        self::assertSame(
            400,
            $postResponse->getStatusCode(),
            'Location: ' . $postResponse->getHeaderLine('Location')
            . '; body: ' . (string) $postResponse->getBody()
        );
    }

    public function testSuccessfulLoginRotatesSessionIdAndSetsCookieAttributes(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users(username, password_hash, role) VALUES (?, ?, ?)'
        );
        $statement->execute([
            'operator',
            password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'admin',
        ]);

        $form = $this->app->handle($this->request('GET', '/login'));
        self::assertSame(200, $form->getStatusCode());

        $cookie = $this->cookiePair($form->getHeaderLine('Set-Cookie'));
        preg_match('/name="(csrf_name)" value="([^"]+)"/', (string) $form->getBody(), $name);
        preg_match('/name="(csrf_value)" value="([^"]+)"/', (string) $form->getBody(), $value);
        self::assertCount(3, $name);
        self::assertCount(3, $value);

        $response = $this->app->handle(
            $this->request('POST', '/login')
                ->withCookieParams(['mirvmon_test' => $cookie[1]])
                ->withParsedBody([
                    $name[1] => $name[2],
                    $value[1] => $value[2],
                    'username' => 'operator',
                    'password' => 'correct horse battery staple',
                ])
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertStringContainsString('HttpOnly', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        self::assertNotSame($cookie[1], $this->cookiePair($response->getHeaderLine('Set-Cookie'))[1]);
    }

    public function testOversizedRequestIsRejectedBeforeController(): void
    {
        $response = $this->app->handle(
            $this->request('POST', '/api/v1/metrics')
                ->withHeader('Content-Length', '2048')
        );

        self::assertSame(413, $response->getStatusCode());
    }

    public function testOversizedChunkedRequestIsRejectedFromStreamSize(): void
    {
        $response = $this->app->handle(
            $this->request('POST', '/api/v1/metrics')
                ->withBody((new StreamFactory())->createStream(str_repeat('x', 2048)))
        );

        self::assertSame(413, $response->getStatusCode());
    }

    public function testReadinessEndpointDoesNotCreateASession(): void
    {
        $response = $this->app->handle($this->request('GET', '/readyz'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testLivenessEndpointIsAvailableWithoutFrankenPhp(): void
    {
        $response = $this->app->handle($this->request('GET', '/livez'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('alive', (string) $response->getBody());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testAgentConfigEndpointDoesNotCreateSessions(): void
    {
        $config = $this->app->handle(
            $this->request('GET', '/api/v1/agent/config')
                ->withHeader('Accept', 'application/json')
        );
        self::assertSame(401, $config->getStatusCode());
        self::assertSame('', $config->getHeaderLine('Set-Cookie'));
    }

    public function testExpiredSessionIsRejectedAndReceivesReplacementCookie(): void
    {
        session_name('mirvmon_test');
        session_id(bin2hex(random_bytes(16)));
        session_start();
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'admin';
        $_SESSION['role'] = 'admin';
        $_SESSION['_created_at'] = time() - 4000;
        $_SESSION['_last_seen_at'] = time() - 2000;
        $expiredId = session_id();
        session_write_close();

        $response = $this->app->handle(
            $this->request('GET', '/api/v1/agent/1/processes')
                ->withCookieParams(['mirvmon_test' => $expiredId])
                ->withHeader('Accept', 'application/json')
        );

        self::assertSame(401, $response->getStatusCode());
        $replacement = $this->cookiePair($response->getHeaderLine('Set-Cookie'));
        self::assertNotSame($expiredId, $replacement[1]);
    }

    public function testSessionStorageLifetimeCoversAbsoluteTimeout(): void
    {
        $this->app->handle($this->request('GET', '/setup'));

        self::assertGreaterThanOrEqual(43200, (int) ini_get('session.gc_maxlifetime'));
    }

    /**
     * @return array{string, string}
     */
    private function authenticatedSession(string $role = 'admin'): array
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO users(id, username, password_hash, role)
             VALUES (?, ?, ?, ?)'
        );
        $statement->execute([
            1,
            $role === 'admin' ? 'admin' : 'viewer',
            password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            $role,
        ]);

        session_name('mirvmon_test');
        session_id(bin2hex(random_bytes(16)));
        session_start();
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = $role === 'admin' ? 'admin' : 'viewer';
        $_SESSION['role'] = $role;
        $id = session_id();
        session_write_close();

        return ['mirvmon_test', $id];
    }

    private function request(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1']
        );
    }

    /**
     * @return array{string, string}
     */
    private function cookiePair(string $header): array
    {
        preg_match('/^([^=]+)=([^;]+)/', $header, $match);
        self::assertCount(3, $match);

        return [$match[1], $match[2]];
    }

    /** @return array{string, string, string, string} */
    private function csrfFields(string $html): array
    {
        preg_match('/name="(csrf_name)" value="([^"]+)"/', $html, $name);
        preg_match('/name="(csrf_value)" value="([^"]+)"/', $html, $value);
        self::assertCount(3, $name);
        self::assertCount(3, $value);

        return [$name[1], $name[2], $value[1], $value[2]];
    }
}
