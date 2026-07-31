<?php

declare(strict_types=1);

namespace Tests\Integration\Notifications;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Notifications\MetricChartRenderer;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class MetricChartRendererTest extends TestCase
{
    private static ?PDO $pdo = null;
    private MetricChartRenderer $renderer;
    private int $serverId;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('The chart renderer needs the gd extension.');
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
        $this->renderer = new MetricChartRenderer(self::$pdo);
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('chart-server') RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testTheLastHourOfAMetricBecomesAPng(): void
    {
        $this->seed('cpu_load', 40);

        $png = $this->renderer->render([
            'server_id' => $this->serverId,
            'server_name' => 'Сервер-01',
            'metric' => 'cpu_load',
            'threshold' => 90.0,
        ]);

        self::assertIsString($png);
        $size = getimagesizefromstring($png);
        self::assertNotFalse($size);
        self::assertSame(IMAGETYPE_PNG, $size[2]);
        self::assertSame(900, $size[0]);
        self::assertSame(320, $size[1]);
    }

    public function testThereIsNothingToDrawWithoutEnoughHistory(): void
    {
        $this->seed('cpu_load', 1);

        self::assertNull($this->renderer->render([
            'server_id' => $this->serverId,
            'metric' => 'cpu_load',
        ]));
    }

    public function testEventsWithoutAMetricProduceNoChart(): void
    {
        $this->seed('cpu_load', 10);

        // Offline and service events name no metric.
        self::assertNull($this->renderer->render([
            'server_id' => $this->serverId,
            'server_name' => 'chart-server',
        ]));
        self::assertNull($this->renderer->render(['metric' => 'cpu_load']));
    }

    public function testSamplesOlderThanTheWindowAreIgnored(): void
    {
        $this->seed('cpu_load', 10, 240);

        self::assertNull($this->renderer->render([
            'server_id' => $this->serverId,
            'metric' => 'cpu_load',
        ]));
    }

    private function seed(string $metric, int $points, int $ageMinutes = 0): void
    {
        $statement = self::$pdo?->prepare(
            "INSERT INTO metric_names (name, unit) VALUES (:name, '%')
             ON CONFLICT (name) DO UPDATE SET unit = EXCLUDED.unit
             RETURNING id"
        );
        $statement?->execute(['name' => $metric]);
        $metricId = (int) $statement?->fetchColumn();

        $insert = self::$pdo?->prepare(
            'INSERT INTO metric_samples
                (server_id, metric_id, sample_time, sample_id, value)
             VALUES (:server_id, :metric_id, :sample_time, gen_random_uuid(), :value)'
        );
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        for ($index = 0; $index < $points; $index++) {
            $minutesAgo = $ageMinutes + $points - $index;
            $insert?->execute([
                'server_id' => $this->serverId,
                'metric_id' => $metricId,
                'sample_time' => $now->modify("-{$minutesAgo} minutes")->format(DATE_ATOM),
                'value' => 40 + $index,
            ]);
        }
    }
}
