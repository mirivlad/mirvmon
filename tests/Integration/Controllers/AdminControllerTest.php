<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\AdminController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Repositories\WorkerHeartbeatRepository;
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class AdminControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AdminController $controller;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }

        self::$pdo = ConnectionFactory::connect([
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '5432'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'DB_SSLMODE' => (string) (getenv('TEST_DB_SSLMODE') ?: 'disable'),
        ]);
        (new Migrator(self::$pdo, dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        self::$pdo?->beginTransaction();
        $twig = Twig::create(dirname(__DIR__, 3) . '/templates', ['cache' => false]);
        $twig->getEnvironment()->addGlobal('session', []);
        $this->controller = new AdminController(
            self::$pdo,
            $twig,
            new NotificationSettingsRepository(
                self::$pdo,
                new SecretCipher(str_repeat('a', 32))
            ),
            new NotificationOutboxRepository(self::$pdo),
            new WorkerHeartbeatRepository(self::$pdo)
        );
        $_SESSION = ['role' => 'admin'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testUserCreateAndEditUsePostgresUpsert(): void
    {
        $create = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/users/save')
            ->withParsedBody([
                'username' => 'managed-user',
                'email' => 'managed@example.test',
                'password' => 'a-secure-password',
                'role' => 'user',
                'telegram_chat_id' => '10001',
                'email_for_alerts' => 'alerts@example.test',
            ]);

        $response = $this->controller->saveUser(
            $create,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(302, $response->getStatusCode());
        $user = self::$pdo?->query(
            "SELECT id, password_hash FROM users WHERE username = 'managed-user'"
        )->fetch();
        self::assertIsArray($user);
        self::assertTrue(password_verify('a-secure-password', $user['password_hash']));

        $edit = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/users/save')
            ->withParsedBody([
                'user_id' => (string) $user['id'],
                'username' => 'managed-user',
                'email' => 'managed@example.test',
                'password' => '',
                'role' => 'user',
                'telegram_chat_id' => '10002',
                'email_for_alerts' => 'new-alerts@example.test',
            ]);
        $this->controller->saveUser(
            $edit,
            (new ResponseFactory())->createResponse(),
            []
        );

        $settings = self::$pdo?->query(
            'SELECT telegram_chat_id, email_for_alerts
             FROM user_notification_settings
             WHERE user_id = ' . (int) $user['id']
        )->fetch();
        self::assertSame('10002', $settings['telegram_chat_id']);
        self::assertSame('new-alerts@example.test', $settings['email_for_alerts']);
        self::assertTrue(self::$pdo?->inTransaction());
    }

    public function testLastAdministratorCannotBeDemoted(): void
    {
        $adminId = $this->insertUser('only-admin', 'admin');
        $_SESSION['user_id'] = $adminId;

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/users/save')
            ->withParsedBody([
                'user_id' => (string) $adminId,
                'username' => 'only-admin',
                'email' => '',
                'password' => '',
                'role' => 'user',
                'telegram_chat_id' => '',
                'email_for_alerts' => '',
            ]);
        $this->controller->saveUser(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(
            'admin',
            self::$pdo?->query(
                'SELECT role FROM users WHERE id = ' . $adminId
            )->fetchColumn()
        );
        self::assertStringContainsString(
            'администратор',
            mb_strtolower((string) ($_SESSION['flash_message'] ?? ''))
        );
    }

    public function testDefaultsAreStoredInAppSettings(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/defaults/save')
            ->withParsedBody([
                'default_offline_timeout' => '420',
                'default_warning_threshold' => '65.5',
                'default_critical_threshold' => '91',
                'default_duration_seconds' => '120',
                'default_recovery_duration_seconds' => '600',
                'website_default_interval_seconds' => '90',
                'website_http_timeout_seconds' => '20',
                'website_tls_warning_days' => '28',
                'website_tls_critical_days' => '5',
                'website_domain_warning_days' => '45',
                'website_domain_critical_days' => '10',
                'website_worker_concurrency' => '12',
            ]);
        $this->controller->saveDefaultSettings(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        $rows = self::$pdo?->query(
            "SELECT setting_key, setting_value #>> '{}' AS value
             FROM app_settings
             WHERE setting_key LIKE 'default_%'
             ORDER BY setting_key"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('420', $rows['default_offline_timeout']);
        self::assertSame('65.5', $rows['default_warning_threshold']);
        self::assertSame('91', $rows['default_critical_threshold']);
        self::assertSame('120', $rows['default_duration_seconds']);
        self::assertSame('600', $rows['default_recovery_duration_seconds']);
        self::assertSame(
            '90',
            (string) self::$pdo?->query(
                "SELECT setting_value #>> '{}' FROM app_settings
                 WHERE setting_key = 'website_default_interval_seconds'"
            )->fetchColumn()
        );
        self::assertSame(
            '12',
            (string) self::$pdo?->query(
                "SELECT setting_value #>> '{}' FROM app_settings
                 WHERE setting_key = 'website_worker_concurrency'"
            )->fetchColumn()
        );

        $page = $this->controller->defaultSettings(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/defaults'),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $page->getBody();
        self::assertStringContainsString('value="420"', $html);
        self::assertStringContainsString('value="120"', $html);
        self::assertStringContainsString('value="90"', $html);
        self::assertStringContainsString('value="12"', $html);
        self::assertStringNotContainsString('CRON', $html);
    }

    public function testWebsiteDefaultsRejectCriticalThresholdAboveWarning(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/defaults/save')
            ->withParsedBody([
                'default_offline_timeout' => '420',
                'default_warning_threshold' => '65',
                'default_critical_threshold' => '90',
                'default_duration_seconds' => '0',
                'default_recovery_duration_seconds' => '0',
                'website_default_interval_seconds' => '60',
                'website_http_timeout_seconds' => '15',
                'website_tls_warning_days' => '7',
                'website_tls_critical_days' => '21',
                'website_domain_warning_days' => '30',
                'website_domain_critical_days' => '7',
                'website_worker_concurrency' => '10',
            ]);

        $this->controller->saveDefaultSettings(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertStringContainsString(
            'критичес',
            mb_strtolower((string) ($_SESSION['flash_message'] ?? ''))
        );
    }

    private function insertUser(string $username, string $role): int
    {
        $statement = self::$pdo?->prepare(
            'INSERT INTO users (username, password_hash, role)
             VALUES (:username, :password_hash, :role)
             RETURNING id'
        );
        $statement?->execute([
            'username' => $username,
            'password_hash' => password_hash('a-secure-password', PASSWORD_DEFAULT),
            'role' => $role,
        ]);

        return (int) $statement?->fetchColumn();
    }
}
