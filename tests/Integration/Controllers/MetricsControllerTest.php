<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\Api\MetricsController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Domain\Metrics\MetricsValidator;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\AgentUpdateRepository;
use App\Services\MetricsIngestionService;
use App\Services\AgentVersionService;
use App\Services\ThresholdEvaluator;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class MetricsControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private MetricsController $controller;
    private string $token;

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
        $serverId = self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('api-server') RETURNING id"
        )->fetchColumn();
        $this->token = str_repeat('c', 64);
        self::$pdo?->prepare(
            'INSERT INTO agent_tokens (server_id, token_hash)
             VALUES (:server_id, :token_hash)'
        )->execute([
            'server_id' => $serverId,
            'token_hash' => hash('sha256', $this->token),
        ]);

        $outbox = new NotificationOutboxRepository(self::$pdo);
        $this->controller = new MetricsController(
            self::$pdo,
            new MetricsValidator(),
            new MetricsIngestionService(
                self::$pdo,
                new ThresholdEvaluator(),
                $outbox,
                new AgentUpdateRepository(self::$pdo),
                new AgentVersionService()
            )
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testMalformedJsonAndInvalidEnvelopeHaveStableErrors(): void
    {
        $malformed = $this->controller->collectMetrics(
            $this->request('{'),
            (new ResponseFactory())->createResponse(),
            []
        );
        self::assertSame(400, $malformed->getStatusCode());
        self::assertSame(
            ['error' => ['code' => 'invalid_json']],
            json_decode((string) $malformed->getBody(), true)
        );

        $payload = $this->payload();
        $payload['version'] = 1;
        $invalid = $this->controller->collectMetrics(
            $this->request((string) json_encode($payload)),
            (new ResponseFactory())->createResponse(),
            []
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            ['error' => ['code' => 'unsupported_version']],
            json_decode((string) $invalid->getBody(), true)
        );
    }

    public function testInvalidTokenIsUnauthorizedWithoutLeakingDetails(): void
    {
        $payload = $this->payload();
        $payload['token'] = str_repeat('x', 64);
        $response = $this->controller->collectMetrics(
            $this->request((string) json_encode($payload)),
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ['error' => ['code' => 'invalid_token']],
            json_decode((string) $response->getBody(), true)
        );
    }

    public function testAcceptedAndDuplicateSamplesReturnDifferentSuccessStatuses(): void
    {
        $body = (string) json_encode($this->payload());

        $accepted = $this->controller->collectMetrics(
            $this->request($body),
            (new ResponseFactory())->createResponse(),
            []
        );
        $duplicate = $this->controller->collectMetrics(
            $this->request($body),
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(202, $accepted->getStatusCode());
        self::assertSame(
            ['accepted' => true, 'duplicate' => false],
            json_decode((string) $accepted->getBody(), true)
        );
        self::assertSame(200, $duplicate->getStatusCode());
        self::assertSame(
            ['accepted' => true, 'duplicate' => true],
            json_decode((string) $duplicate->getBody(), true)
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'version' => 2,
            'sample_id' => '30000000-0000-4000-8000-000000000001',
            'sample_time' => gmdate('Y-m-d\TH:i:s\Z', time() - 10),
            'token' => $this->token,
            'metrics' => ['cpu_load' => 12.5],
        ];
    }

    private function request(string $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://monitor.example/api/v1/metrics')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($body));
    }
}
