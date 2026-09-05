<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class WorkerRuntimeContractTest extends TestCase
{
    public function testInfrastructureFailureTerminatesWorkersForSupervisorRestart(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'bin/connectivity-worker',
            'bin/notification-worker',
            'bin/offline-worker',
            'bin/website-check-worker',
        ] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertMatchesRegularExpression(
                '/catch \\(Throwable \\$exception\\).*?exit\\(1\\);/s',
                $source,
                $file . ' must terminate after an infrastructure failure.'
            );
            self::assertMatchesRegularExpression(
                '/catch \\(PDOException\\).*?\\$(?:worker|runtime) = null;/s',
                $source,
                $file . ' must discard a disconnected PDO graph.'
            );
        }
    }
}
