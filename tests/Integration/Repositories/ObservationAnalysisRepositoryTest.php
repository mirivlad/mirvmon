<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\ObservationAnalysisRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ObservationAnalysisRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;

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

    public function testWeeklyHourBaselineUsesOperationalTimezoneAndRepeatingPattern(): void
    {
        $serverName = 'contextual-baseline-test';
        $this->cleanup($serverName);
        $now = new DateTimeImmutable('2026-09-19T10:00:00+08:00');

        try {
            $server = self::$pdo?->prepare(
                "INSERT INTO servers (name, is_active) VALUES (:name, TRUE) RETURNING id"
            );
            $server?->execute(['name' => $serverName]);
            $serverId = (int) $server?->fetchColumn();
            $metricId = (int) self::$pdo?->query(
                "SELECT id FROM metric_names WHERE name = 'cpu_load'"
            )->fetchColumn();

            $insert = self::$pdo?->prepare(
                'INSERT INTO metric_samples (sample_time, server_id, metric_id, sample_id, value)
                 VALUES (:sample_time, :server_id, :metric_id, :sample_id, :value)'
            );
            $counter = 1;
            for ($week = 1; $week <= 8; $week++) {
                $anchor = $now->modify('-' . $week . ' weeks');
                foreach ([9 => 35.0, 10 => 50.0, 11 => 65.0] as $hour => $value) {
                    $time = $anchor->setTime($hour, 15);
                    $insert?->execute([
                        'sample_time' => $time->format(DATE_ATOM),
                        'server_id' => $serverId,
                        'metric_id' => $metricId,
                        'sample_id' => sprintf('11111111-1111-1111-1111-%012d', $counter++),
                        'value' => $value,
                    ]);
                }
                foreach ([1, 4, 7, 14, 18, 22] as $hour) {
                    $time = $anchor->setTime($hour, 15);
                    $insert?->execute([
                        'sample_time' => $time->format(DATE_ATOM),
                        'server_id' => $serverId,
                        'metric_id' => $metricId,
                        'sample_id' => sprintf('22222222-2222-2222-2222-%012d', $counter++),
                        'value' => 5.0,
                    ]);
                }
            }

            self::$pdo?->exec(sprintf(
                "CALL refresh_continuous_aggregate('metric_samples_hourly', '%s', '%s')",
                $now->modify('-57 days')->format('Y-m-d H:i:sP'),
                $now->modify('-1 hour')->format('Y-m-d H:i:sP')
            ));

            $rows = (new ObservationAnalysisRepository(self::$pdo, 'Asia/Irkutsk'))
                ->levelBaselines($now);
            $row = null;
            foreach ($rows as $candidate) {
                if ((int) $candidate['server_id'] === $serverId && $candidate['metric_name'] === 'cpu_load') {
                    $row = $candidate;
                    break;
                }
            }

            self::assertNotNull($row);
            self::assertSame('weekly_hour', $row['context']);
            self::assertSame(6, $row['local_weekday']);
            self::assertSame(10, $row['local_hour']);
            self::assertGreaterThanOrEqual(4, $row['weeks']);
            self::assertGreaterThan(30.0, $row['median']);
        } finally {
            $this->cleanup($serverName);
        }
    }

    private function cleanup(string $serverName): void
    {
        $statement = self::$pdo?->prepare('DELETE FROM servers WHERE name = :name');
        $statement?->execute(['name' => $serverName]);
    }
}
