<?php

declare(strict_types=1);

namespace Tests\Integration\Middleware;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\I18n\Translator;
use App\Middlewares\AuditTrailMiddleware;
use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\ServerRepository;
use App\Services\AuditLogger;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AuditTrailMiddlewareTest extends TestCase
{
    private static ?PDO $pdo = null;
    /** @var array<string, mixed> */
    private array $session = [];
    private int $userId;
    private int $serverId;

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
        $this->session = $_SESSION ?? [];
        self::$pdo?->beginTransaction();

        $user = self::$pdo?->prepare(
            "INSERT INTO users (username, password_hash, role)
             VALUES ('audit-middleware-admin', :password_hash, 'admin')
             RETURNING id"
        );
        $user?->execute(['password_hash' => password_hash('test-password-123', PASSWORD_DEFAULT)]);
        $this->userId = (int) $user?->fetchColumn();

        $server = self::$pdo?->prepare(
            "INSERT INTO servers (name, address, description)
             VALUES ('audit-before', '192.0.2.10', 'before')
             RETURNING id"
        );
        $server?->execute();
        $this->serverId = (int) $server?->fetchColumn();

        $_SESSION = [
            'user_id' => $this->userId,
            'username' => 'audit-middleware-admin',
            'role' => 'admin',
        ];
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
        $_SESSION = $this->session;
    }

    public function testSuccessfulServerMutationIsAuditedFromPersistedState(): void
    {
        $middleware = $this->middleware();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/servers/' . $this->serverId)
            ->withParsedBody(['name' => 'audit-after']);

        $response = $middleware->process($request, new class(self::$pdo, $this->serverId) implements RequestHandlerInterface {
            public function __construct(private readonly PDO $pdo, private readonly int $serverId)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $statement = $this->pdo->prepare(
                    'UPDATE servers SET name = :name, description = :description WHERE id = :id'
                );
                $statement->execute([
                    'id' => $this->serverId,
                    'name' => 'audit-after',
                    'description' => 'after',
                ]);
                return (new ResponseFactory())->createResponse(302)
                    ->withHeader('Location', '/servers/' . $this->serverId . '/edit');
            }
        });

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('audit-after', (string) self::$pdo?->query(
            'SELECT name FROM servers WHERE id = ' . $this->serverId
        )->fetchColumn());

        $statement = self::$pdo?->prepare(
            'SELECT actor_user_id, actor_username, action, object_type, object_id,
                    object_label, description, metadata::text AS metadata
             FROM audit_log
             WHERE action = :action AND object_id = :object_id
             ORDER BY id DESC LIMIT 1'
        );
        $statement?->execute([
            'action' => 'server.update',
            'object_id' => (string) $this->serverId,
        ]);
        $row = $statement?->fetch();

        self::assertIsArray($row);
        self::assertSame($this->userId, (int) $row['actor_user_id']);
        self::assertSame('audit-middleware-admin', $row['actor_username']);
        self::assertSame('server.update', $row['action']);
        self::assertSame('server', $row['object_type']);
        self::assertSame((string) $this->serverId, $row['object_id']);
        self::assertSame('audit-after', $row['object_label']);
        self::assertStringContainsString('audit-after', (string) $row['description']);
        self::assertStringContainsString('name', (string) $row['metadata']);
        self::assertStringContainsString('description', (string) $row['metadata']);
    }

    public function testFailedMutationRollsBackChangeAndDoesNotWriteAuditRow(): void
    {
        $middleware = $this->middleware();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/servers/' . $this->serverId)
            ->withParsedBody(['name' => 'must-rollback']);

        $response = $middleware->process($request, new class(self::$pdo, $this->serverId) implements RequestHandlerInterface {
            public function __construct(private readonly PDO $pdo, private readonly int $serverId)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $statement = $this->pdo->prepare('UPDATE servers SET name = :name WHERE id = :id');
                $statement->execute(['id' => $this->serverId, 'name' => 'must-rollback']);
                $_SESSION['flash_type'] = 'danger';
                $_SESSION['flash_message'] = 'failed';
                return (new ResponseFactory())->createResponse(302)
                    ->withHeader('Location', '/servers/' . $this->serverId . '/edit');
            }
        });

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('audit-before', (string) self::$pdo?->query(
            'SELECT name FROM servers WHERE id = ' . $this->serverId
        )->fetchColumn());

        $statement = self::$pdo?->prepare(
            'SELECT count(*) FROM audit_log WHERE action = :action AND object_id = :object_id'
        );
        $statement?->execute([
            'action' => 'server.update',
            'object_id' => (string) $this->serverId,
        ]);
        self::assertSame('0', (string) $statement?->fetchColumn());
    }

    private function middleware(): AuditTrailMiddleware
    {
        $repository = new AuditLogRepository(self::$pdo);
        return new AuditTrailMiddleware(
            self::$pdo,
            new AuditLogger($repository),
            new ServerRepository(self::$pdo),
            new NotificationOutboxRepository(self::$pdo),
            new Translator(null, dirname(__DIR__, 3) . '/translations')
        );
    }
}
