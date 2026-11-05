<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\ChimePortTransport;
use App\Notifications\NotificationTransportException;
use PHPUnit\Framework\TestCase;

final class ChimePortTransportTest extends TestCase
{
    private string $tokenFile;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'chimeport-test-');
        self::assertIsString($file);
        $this->tokenFile = $file;
        file_put_contents($file, 'cp_t_' . str_repeat('A', 43) . "\n");
        chmod($file, 0600);
    }

    protected function tearDown(): void
    {
        @unlink($this->tokenFile);
    }

    public function testDownAlertUsesScopedChannelAndStableIdempotencyKey(): void
    {
        $captured = [];
        $transport = new ChimePortTransport(
            'https://notify.example.org',
            $this->tokenFile,
            static function (string $url, array $options) use (&$captured): array {
                $captured = ['url' => $url, 'options' => $options];
                return ['status' => 201, 'body' => '{"stored":true}', 'retry_after' => null];
            }
        );
        $transport->send(['id' => 42, 'event_type' => 'offline_detected', 'payload' => []], 'Host down', 'No response');
        self::assertSame('https://notify.example.org/api/v1/messages', $captured['url']);
        self::assertContains('Idempotency-Key: mirvmon-outbox-42', $captured['options'][CURLOPT_HTTPHEADER]);
        self::assertContains('Authorization: Bearer cp_t_' . str_repeat('A', 43), $captured['options'][CURLOPT_HTTPHEADER]);
        self::assertFalse($captured['options'][CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($captured['options'][CURLOPT_SSL_VERIFYPEER]);
        $body = json_decode((string) $captured['options'][CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('monitor/hosts', $body['channel']);
        self::assertSame('urgent', $body['priority']);
        self::assertSame('Host down', $body['title']);
        self::assertArrayNotHasKey('token', $body);
    }

    public function testRecoveredServiceUsesNormalPriorityAndAcceptsReplay(): void
    {
        $payload = [];
        $transport = new ChimePortTransport(
            'https://notify.example.org/',
            $this->tokenFile,
            static function (string $url, array $options) use (&$payload): array {
                $payload = json_decode((string) $options[CURLOPT_POSTFIELDS], true);
                return ['status' => 200, 'body' => '{"duplicate":true}', 'retry_after' => null];
            }
        );
        $transport->send(['id' => 43, 'event_type' => 'service_recovered'], 'Service up', 'Recovered');
        self::assertSame('monitor/services', $payload['channel']);
        self::assertSame('normal', $payload['priority']);
    }

    public function testChannelOverrideSendsAllEventsToServers(): void
    {
        $previous = getenv('CHIMEPORT_CHANNEL');
        putenv('CHIMEPORT_CHANNEL=servers');
        try {
            $observed = [];
            $transport = new ChimePortTransport(
                'https://notify.example.org',
                $this->tokenFile,
                static function (string $url, array $options) use (&$observed): array {
                    $observed = json_decode((string) $options[CURLOPT_POSTFIELDS], true);
                    return ['status' => 201, 'body' => '{"stored":true}', 'retry_after' => null];
                }
            );
            $transport->send(['id' => 67, 'event_type' => 'website_down'], 'Website down', 'No response');
            self::assertSame('servers', $observed['channel']);
        } finally {
            putenv($previous === false ? 'CHIMEPORT_CHANNEL' : 'CHIMEPORT_CHANNEL=' . $previous);
        }
    }

    public function testInvalidChannelOverrideIsRejected(): void
    {
        $previous = getenv('CHIMEPORT_CHANNEL');
        putenv('CHIMEPORT_CHANNEL=../sensitive');
        try {
            $transport = new ChimePortTransport('https://notify.example.org', $this->tokenFile);
            $this->expectException(NotificationTransportException::class);
            $this->expectExceptionMessage('chimeport_channel_invalid');
            $transport->send(['id' => 68, 'event_type' => 'test'], 'Test', 'Invalid');
        } finally {
            putenv($previous === false ? 'CHIMEPORT_CHANNEL' : 'CHIMEPORT_CHANNEL=' . $previous);
        }
    }

    public function testRateLimitCarriesRetryAfterWithoutLeakingResponseBody(): void
    {
        $transport = new ChimePortTransport(
            'https://notify.example.org',
            $this->tokenFile,
            static fn (): array => ['status' => 429, 'body' => 'private', 'retry_after' => 45]
        );
        try {
            $transport->send(['id' => 44, 'event_type' => 'website_down'], 'Site down', 'No response');
            self::fail('Expected rate limit failure');
        } catch (NotificationTransportException $e) {
            self::assertSame('chimeport_rate_limited', $e->getMessage());
            self::assertSame(45, $e->retryAfterSeconds);
        }
    }

    public function testRejectsUrlsWithUserinfo(): void
    {
        $transport = new ChimePortTransport('https://user@notify.example.org', $this->tokenFile);
        $this->expectException(NotificationTransportException::class);
        $this->expectExceptionMessage('chimeport_url_invalid');
        $transport->send(['id' => 45, 'event_type' => 'test'], 'Test', 'Message');
    }

    public function testMissingTokenFailsClosed(): void
    {
        unlink($this->tokenFile);
        $transport = new ChimePortTransport('https://notify.example.org', $this->tokenFile);
        $this->expectException(NotificationTransportException::class);
        $this->expectExceptionMessage('chimeport_token_unavailable');
        $transport->send(['id' => 46, 'event_type' => 'test'], 'Test', 'Message');
    }
}
