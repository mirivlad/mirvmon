<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Metrics;

use App\Domain\Metrics\MetricsValidationException;
use App\Domain\Metrics\MetricsValidator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricsValidatorTest extends TestCase
{
    private MetricsValidator $validator;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->validator = new MetricsValidator();
        $this->now = new DateTimeImmutable('2026-07-30T12:00:00Z');
    }

    public function testValidEnvelopeIsNormalized(): void
    {
        $envelope = $this->validator->validate($this->validPayload(), $this->now);

        self::assertSame(2, $envelope->version);
        self::assertSame('018f47a2-8e4c-7d0a-8d8b-45de8fd746a1', $envelope->sampleId);
        self::assertSame('2026-07-30T11:59:00+00:00', $envelope->sampleTime->format(DATE_ATOM));
        self::assertSame(['cpu_load' => 42.5, 'uptime' => 1234.0], $envelope->metrics);
        self::assertSame('postgresql.service', $envelope->services[0]['name']);
        self::assertSame(321, $envelope->processSnapshot['top_cpu'][0]['pid']);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidEnvelopeIsRejected(callable $mutate, string $expectedCode): void
    {
        try {
            $this->validator->validate($mutate($this->validPayload()), $this->now);
            self::fail('Expected validation to fail.');
        } catch (MetricsValidationException $exception) {
            self::assertSame($expectedCode, $exception->errorCode);
        }
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'unknown top-level field' => [
            static function (array $payload): array {
                $payload['unexpected'] = true;
                return $payload;
            },
            'unknown_field',
        ];
        yield 'wrong protocol version' => [
            static function (array $payload): array {
                $payload['version'] = 1;
                return $payload;
            },
            'unsupported_version',
        ];
        yield 'invalid uuid' => [
            static function (array $payload): array {
                $payload['sample_id'] = 'not-a-uuid';
                return $payload;
            },
            'invalid_sample_id',
        ];
        yield 'timestamp not UTC' => [
            static function (array $payload): array {
                $payload['sample_time'] = '2026-07-30T19:59:00+08:00';
                return $payload;
            },
            'sample_time_not_utc',
        ];
        yield 'clock too far in future' => [
            static function (array $payload): array {
                $payload['sample_time'] = '2026-07-30T12:05:01Z';
                return $payload;
            },
            'sample_time_in_future',
        ];
        yield 'sample too old' => [
            static function (array $payload): array {
                $payload['sample_time'] = '2026-07-23T11:59:59Z';
                return $payload;
            },
            'sample_time_too_old',
        ];
        yield 'empty token' => [
            static function (array $payload): array {
                $payload['token'] = '';
                return $payload;
            },
            'invalid_token',
        ];
        yield 'invalid metric name' => [
            static function (array $payload): array {
                $payload['metrics'] = ['CPU Load' => 10];
                return $payload;
            },
            'invalid_metric_name',
        ];
        yield 'non-finite metric' => [
            static function (array $payload): array {
                $payload['metrics']['cpu_load'] = INF;
                return $payload;
            },
            'invalid_metric_value',
        ];
        yield 'too many metrics' => [
            static function (array $payload): array {
                $payload['metrics'] = [];
                for ($index = 0; $index < 101; $index++) {
                    $payload['metrics']['metric_' . $index] = $index;
                }
                return $payload;
            },
            'too_many_metrics',
        ];
        yield 'unknown service field' => [
            static function (array $payload): array {
                $payload['services'][0]['pid'] = 1;
                return $payload;
            },
            'unknown_service_field',
        ];
        yield 'invalid service status' => [
            static function (array $payload): array {
                $payload['services'][0]['status'] = 'failed';
                return $payload;
            },
            'invalid_service_status',
        ];
        yield 'oversized process snapshot' => [
            static function (array $payload): array {
                $payload['process_snapshot']['top_cpu'][0]['command'] = str_repeat('x', 70000);
                return $payload;
            },
            'process_snapshot_too_large',
        ];
        yield 'unknown process field' => [
            static function (array $payload): array {
                $payload['process_snapshot']['top_cpu'][0]['environment'] = [];
                return $payload;
            },
            'unknown_process_field',
        ];
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'version' => 2,
            'sample_id' => '018f47a2-8e4c-7d0a-8d8b-45de8fd746a1',
            'sample_time' => '2026-07-30T11:59:00Z',
            'token' => str_repeat('a', 64),
            'metrics' => [
                'cpu_load' => 42.5,
                'uptime' => 1234,
            ],
            'services' => [[
                'name' => 'postgresql.service',
                'status' => 'running',
                'load_state' => 'loaded',
                'active_state' => 'active',
                'sub_state' => 'running',
            ]],
            'process_snapshot' => [
                'top_cpu' => [[
                    'pid' => 321,
                    'name' => 'postgres',
                    'command' => 'postgres',
                    'value' => 10.5,
                ]],
                'top_memory' => [],
            ],
        ];
    }

    public function testAgentVersionIsOptionalAndBounded(): void
    {
        $validator = new MetricsValidator();
        $payload = [
            'version' => 2,
            'sample_id' => '20000000-0000-4000-8000-000000000001',
            'sample_time' => (new DateTimeImmutable())->format('Y-m-d\\TH:i:s\\Z'),
            'token' => str_repeat('t', 64),
            'metrics' => ['cpu_load' => 1.0],
        ];

        // An agent released before this field keeps being accepted.
        self::assertNull($validator->validate($payload)->agentVersion);

        $payload['agent_version'] = '2.0.0';
        self::assertSame('2.0.0', $validator->validate($payload)->agentVersion);

        $payload['agent_version'] = "2.0.0\nrm -rf";
        $this->expectException(MetricsValidationException::class);
        $validator->validate($payload);
    }
}
