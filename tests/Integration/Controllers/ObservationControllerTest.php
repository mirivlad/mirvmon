<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\ObservationController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\ObservationRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class ObservationControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private ObservationRepository $repository;
    private ObservationController $controller;
    private int $serverId;
    private int $metricId;

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
        $twig->getEnvironment()->addGlobal('session', ['role' => 'operator']);
        $this->repository = new ObservationRepository(self::$pdo);
        $this->controller = new ObservationController($twig, $this->repository);
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('observation-server') RETURNING id"
        )->fetchColumn();
        $this->metricId = (int) self::$pdo?->query(
            "SELECT id FROM metric_names WHERE name = 'cpu_load'"
        )->fetchColumn();
        $_SESSION['user_id'] = (int) self::$pdo?->query(
            "INSERT INTO users (username, password_hash, role)\n             VALUES ('operator', 'test-hash', 'operator') RETURNING id"
        )->fetchColumn();
        $_SESSION['username'] = 'operator';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['username']);
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testActiveViewRendersOperatorActions(): void
    {
        $anomalyId = $this->createObservation('anomaly', 'level_shift_v1:cpu_load:p1:b20');
        $predictionId = $this->createObservation('prediction', 'disk_growth_v1:disk_used_root');

        $response = $this->controller->index(
            (new ServerRequestFactory())->createServerRequest('GET', '/observations'),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('observation-server', $html);
        self::assertStringContainsString('/observations/' . $anomalyId . '/handle', $html);
        self::assertStringContainsString('/observations/' . $anomalyId . '/accept-normal', $html);
        self::assertStringContainsString('/observations/' . $predictionId . '/handle', $html);
        self::assertStringContainsString('/observations/' . $predictionId . '/assess', $html);
    }

    public function testPredictionCanBeMarkedHandled(): void
    {
        $id = $this->createObservation('prediction', 'disk_growth_v1:disk_used_root');
        $response = $this->controller->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/observations/' . $id . '/handle'),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $id]
        );

        self::assertSame('/observations', $response->getHeaderLine('Location'));
        self::assertSame('handled', (string) self::$pdo?->query(
            'SELECT status FROM observations WHERE id = ' . $id
        )->fetchColumn());
        self::assertSame('operator', (string) self::$pdo?->query(
            'SELECT handled_by_username FROM observations WHERE id = ' . $id
        )->fetchColumn());
    }

    public function testAnomalyCanBeReviewedWithoutAcceptingItAsNormal(): void
    {
        $id = $this->createObservation('anomaly', 'level_shift_v1:cpu_load:p1:b20');
        $response = $this->controller->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/observations/' . $id . '/handle'),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $id]
        );

        self::assertSame('/observations', $response->getHeaderLine('Location'));
        self::assertSame('handled', (string) self::$pdo?->query(
            'SELECT status FROM observations WHERE id = ' . $id
        )->fetchColumn());
        self::assertNull(self::$pdo?->query(
            'SELECT accepted_at FROM observations WHERE id = ' . $id
        )->fetchColumn() ?: null);
    }

    public function testAnomalyCanBeAcceptedAndReset(): void
    {
        $id = $this->createObservation('anomaly', 'level_shift_v1:cpu_load:p1:b20');
        $this->controller->acceptNormal(
            (new ServerRequestFactory())->createServerRequest('POST', '/observations/' . $id . '/accept-normal'),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $id]
        );
        self::assertSame('accepted_normal', (string) self::$pdo?->query(
            'SELECT status FROM observations WHERE id = ' . $id
        )->fetchColumn());

        $response = $this->controller->resetNormal(
            (new ServerRequestFactory())->createServerRequest('POST', '/observations/' . $id . '/reset-normal'),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $id]
        );
        self::assertSame('/observations', $response->getHeaderLine('Location'));
        self::assertSame('resolved', (string) self::$pdo?->query(
            'SELECT status FROM observations WHERE id = ' . $id
        )->fetchColumn());
    }

    private function createObservation(string $kind, string $fingerprint): int
    {
        $candidate = [
            'kind' => $kind,
            'detector' => $kind === 'prediction' ? 'disk_growth_v1' : 'level_shift_v1',
            'fingerprint' => $fingerprint,
            'current_value' => $kind === 'prediction' ? 68.0 : 22.0,
            'baseline_value' => $kind === 'prediction' ? 54.0 : 3.0,
            'confidence' => 0.88,
            'forecast_at' => $kind === 'prediction' ? '2026-09-18T08:00:00+00:00' : null,
            'details' => $kind === 'prediction'
                ? ['metric' => 'disk_used_root', 'slope_percent_per_day' => 4.0]
                : ['metric' => 'cpu_load', 'reason' => 'sustained'],
        ];
        $result = $this->repository->recordCandidate(
            $this->serverId,
            $this->metricId,
            $candidate,
            new DateTimeImmutable('2026-09-16T00:00:00+00:00')
        );

        return $result['id'];
    }
}
