<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\GroupController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Services\ServerStatusService;
use App\Services\ServerPlatformService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class GroupControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private GroupController $controller;
    private int $groupId;

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
        $this->controller = new GroupController(
            self::$pdo,
            $twig,
            new ServerStatusService(new ServerPlatformService())
        );
        $this->groupId = (int) self::$pdo?->query(
            "INSERT INTO server_groups (name, icon, color)
             VALUES ('Production', 'fa-server', '#3157d5')
             RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testGroupPageUsesCurrentPostgresServerState(): void
    {
        $freshId = $this->insertServer('fresh-server', '10 seconds');
        $this->insertServer('stale-server', '20 minutes');
        self::$pdo?->exec(
            "INSERT INTO alerts (server_id, kind, subject, severity)
             VALUES ({$freshId}, 'metric', 'cpu_load', 'warning')"
        );

        $response = $this->controller->show(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                '/groups/' . $this->groupId
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $this->groupId]
        );
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('fresh-server', $html);
        self::assertStringContainsString('stale-server', $html);
        self::assertStringContainsString('Внимание', $html);
        self::assertStringContainsString('Нет данных', $html);
        self::assertStringContainsString('Активных проблем', $html);
        self::assertStringContainsString('group-summary-grid', $html);
    }

    public function testGroupIndexRendersOperationalSummaryInsteadOfCrudOnlyTable(): void
    {
        $freshId = $this->insertServer('group-online', '10 seconds');
        $this->insertServer('group-offline', '20 minutes');
        self::$pdo?->exec(
            "INSERT INTO alerts (server_id, kind, subject, severity)
             VALUES ({$freshId}, 'metric', 'cpu_load', 'critical')"
        );

        $response = $this->controller->index(
            (new ServerRequestFactory())->createServerRequest('GET', '/groups'),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('group-dashboard-card', $html);
        self::assertStringContainsString('group-status-strip', $html);
        self::assertStringContainsString('Активных проблем', $html);
        self::assertStringContainsString('Production', $html);
        self::assertStringContainsString('group-online', (string) self::$pdo?->query(
            "SELECT name FROM servers WHERE name = 'group-online'"
        )->fetchColumn());
    }

    public function testInvalidGroupDataIsRejected(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/groups')
            ->withParsedBody([
                'name' => '',
                'description' => 'invalid',
                'icon' => 'fa-server" onclick="alert(1)',
                'color' => 'red',
            ]);
        $response = $this->controller->store(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame('/groups/create', $response->getHeaderLine('Location'));
        self::assertSame(
            0,
            (int) self::$pdo?->query(
                "SELECT count(*) FROM server_groups WHERE description = 'invalid'"
            )->fetchColumn()
        );
    }

    private function insertServer(string $name, string $age): int
    {
        $statement = self::$pdo?->prepare(
            "INSERT INTO servers (
                name,
                group_id,
                last_metrics_at,
                offline_timeout_seconds
             ) VALUES (
                :name,
                :group_id,
                CURRENT_TIMESTAMP - CAST(:age AS interval),
                300
             )
             RETURNING id"
        );
        $statement?->execute([
            'name' => $name,
            'group_id' => $this->groupId,
            'age' => $age,
        ]);
        $serverId = (int) $statement?->fetchColumn();

        self::$pdo?->prepare(
            'INSERT INTO agent_tokens (server_id, token_hash, last_used_at)
             VALUES (
                :server_id,
                :token_hash,
                CURRENT_TIMESTAMP - CAST(:age AS interval)
             )'
        )->execute([
            'server_id' => $serverId,
            'token_hash' => hash('sha256', 'group-controller-' . $serverId),
            'age' => $age,
        ]);

        return $serverId;
    }
}
