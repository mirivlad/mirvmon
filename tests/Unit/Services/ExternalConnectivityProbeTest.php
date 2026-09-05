<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ExternalConnectivityProbe;
use PHPUnit\Framework\TestCase;

final class ExternalConnectivityProbeTest extends TestCase
{
    public function testTwoOfThreeSuccessfulTargetsSatisfyQuorum(): void
    {
        $calls = [];
        $probe = new ExternalConnectivityProbe(
            ['one:443', 'two:443', 'three:443'],
            2,
            0.5,
            static function (array $targets, float $timeout) use (&$calls): array {
                $calls[] = [$targets, $timeout];
                return [
                    'one:443' => true,
                    'two:443' => false,
                    'three:443' => true,
                ];
            },
        );

        $result = $probe->probe();

        self::assertTrue($result['available']);
        self::assertSame(2, $result['successes']);
        self::assertSame(1, $result['failures']);
        self::assertSame(['one:443', 'three:443'], $result['successful_targets']);
        self::assertSame(['two:443'], $result['failed_targets']);
        self::assertSame([[['one:443', 'two:443', 'three:443'], 0.5]], $calls);
    }

    public function testTwoOfThreeFailuresMakeConnectivityUnavailable(): void
    {
        $probe = new ExternalConnectivityProbe(
            ['one:443', 'two:443', 'three:443'],
            2,
            0.5,
            static fn (array $targets, float $timeout): array => [
                'one:443' => false,
                'two:443' => false,
                'three:443' => true,
            ],
        );

        $result = $probe->probe();

        self::assertFalse($result['available']);
        self::assertSame(1, $result['successes']);
        self::assertSame(2, $result['failures']);
        self::assertSame(['one:443', 'two:443'], $result['failed_targets']);
    }

    public function testMissingBatchOutcomeIsTreatedAsFailure(): void
    {
        $probe = new ExternalConnectivityProbe(
            ['one:443', 'two:443'],
            2,
            0.5,
            static fn (array $targets, float $timeout): array => ['one:443' => true],
        );

        $result = $probe->probe();

        self::assertFalse($result['available']);
        self::assertSame(['two:443'], $result['failed_targets']);
    }

    public function testDefaultConnectorCanProbeMultipleListeningTcpTargets(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('ext-curl is required for the production connectivity connector.');
        }
        $first = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $second = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($first);
        self::assertIsResource($second);
        try {
            $firstAddress = stream_socket_get_name($first, false);
            $secondAddress = stream_socket_get_name($second, false);
            self::assertIsString($firstAddress);
            self::assertIsString($secondAddress);

            $result = (new ExternalConnectivityProbe(
                [$firstAddress, $secondAddress],
                2,
                0.5,
            ))->probe();

            self::assertTrue($result['available']);
            self::assertSame(2, $result['successes']);
            self::assertSame(0, $result['failures']);
        } finally {
            fclose($first);
            fclose($second);
        }
    }
}
