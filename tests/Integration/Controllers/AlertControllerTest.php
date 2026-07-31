<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\AlertController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\NotificationOutboxRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class AlertControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AlertController $controller;
    private int $alertId;

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
        $this->controller = new AlertController(
            self::$pdo,
            $twig,
            new NotificationOutboxRepository(self::$pdo)
        );

        $serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('alert-server') RETURNING id"
        )->fetchColumn();
        $metricId = (int) self::$pdo?->query(
            "SELECT id FROM metric_names WHERE name = 'cpu_load'"
        )->fetchColumn();
        $statement = self::$pdo?->prepare(
            "INSERT INTO alerts (
                server_id,
                metric_id,
                kind,
                subject,
                value,
                severity
             ) VALUES (
                :server_id,
                :metric_id,
                'metric',
                'cpu_load',
                95.5,
                'critical'
             )
             RETURNING id"
        );
        $statement?->execute(['server_id' => $serverId, 'metric_id' => $metricId]);
        $this->alertId = (int) $statement?->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testActiveAlertsRenderMetricMetadata(): void
    {
        $response = $this->controller->index(
            (new ServerRequestFactory())->createServerRequest('GET', '/alerts'),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('alert-server', $html);
        self::assertStringContainsString('cpu load', mb_strtolower($html));
        self::assertStringContainsString('95.5%', $html);
    }

    public function testResolveUsesPostgresBoolean(): void
    {
        $response = $this->controller->markAsResolved(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                '/alerts/' . $this->alertId . '/resolve'
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $this->alertId]
        );

        self::assertSame('/alerts', $response->getHeaderLine('Location'));
        self::assertTrue((bool) self::$pdo?->query(
            'SELECT resolved FROM alerts WHERE id = ' . $this->alertId
        )->fetchColumn());
    }

    public function testManualResolutionNotifiesTheEnabledChannels(): void
    {
        self::$pdo?->exec(
            "UPDATE notification_settings
             SET telegram_enabled = TRUE,
                 telegram_chat_id = '-100',
                 notify_on_critical = TRUE
             WHERE id = 1"
        );
        $_SESSION['username'] = 'operator';

        $this->controller->markAsResolved(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                '/alerts/' . $this->alertId . '/resolve'
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $this->alertId]
        );

        $statement = self::$pdo?->prepare(
            'SELECT channel, event_type, payload
             FROM notification_outbox
             WHERE alert_id = :id'
        );
        $statement?->execute(['id' => $this->alertId]);
        $job = $statement?->fetch();

        self::assertIsArray($job);
        self::assertSame('telegram', $job['channel']);
        self::assertSame('alert_resolved', $job['event_type']);
        $payload = json_decode((string) $job['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('operator', $payload['resolved_by']);
        self::assertSame('cpu_load', $payload['subject']);
        self::assertSame('critical', $payload['severity']);
        self::assertIsInt($payload['server_id']);
    }

    public function testAnAlreadyResolvedAlertIsNotAnnouncedTwice(): void
    {
        self::$pdo?->exec(
            "UPDATE notification_settings
             SET telegram_enabled = TRUE, telegram_chat_id = '-100'
             WHERE id = 1"
        );
        $request = (new ServerRequestFactory())->createServerRequest(
            'POST',
            '/alerts/' . $this->alertId . '/resolve'
        );

        $this->controller->markAsResolved(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $this->alertId]
        );
        $this->controller->markAsResolved(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $this->alertId]
        );

        self::assertSame(1, (int) self::$pdo?->query(
            'SELECT COUNT(*) FROM notification_outbox WHERE alert_id = ' . $this->alertId
        )->fetchColumn());
    }
}
