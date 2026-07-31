<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\NotificationMessageFormatter;
use PHPUnit\Framework\TestCase;

final class NotificationMessageFormatterTest extends TestCase
{
    public function testMetricAlertContainsOperationalContext(): void
    {
        $formatter = new NotificationMessageFormatter();

        $message = $formatter->format([
            'event_type' => 'metric_triggered',
            'payload' => [
                'type' => 'metric',
                'event' => 'triggered',
                'server_name' => 'edge-1',
                'metric_name' => 'cpu_percent',
                'value' => 91.25,
                'severity' => 'critical',
                'event_time' => '2026-07-30T01:02:03+00:00',
            ],
        ]);

        self::assertStringContainsString('Критическая', $message['subject']);
        self::assertStringContainsString('edge-1', $message['subject']);
        self::assertStringContainsString('cpu_percent', $message['body']);
        self::assertStringContainsString('91.25', $message['body']);
        self::assertStringContainsString('2026-07-30T01:02:03+00:00', $message['body']);
    }

    public function testRecoveryAndTestMessagesAreExplicit(): void
    {
        $formatter = new NotificationMessageFormatter();

        $recovery = $formatter->format([
            'event_type' => 'offline_recovered',
            'payload' => [
                'type' => 'offline',
                'event' => 'recovered',
                'server_name' => 'db-1',
                'event_time' => '2026-07-30T01:02:03+00:00',
            ],
        ]);
        $test = $formatter->format([
            'event_type' => 'test',
            'payload' => [
                'type' => 'test',
                'event_time' => '2026-07-30T01:02:03+00:00',
            ],
        ]);

        self::assertStringContainsString('восстановлен', $recovery['subject']);
        self::assertStringContainsString('Тестовое уведомление', $test['subject']);
    }

    public function testManualResolutionNamesTheOperator(): void
    {
        $message = (new NotificationMessageFormatter())->format([
            'event_type' => 'alert_resolved',
            'payload' => [
                'type' => 'alert',
                'event' => 'resolved_manually',
                'server_id' => 7,
                'server_name' => 'edge-1',
                'subject' => 'cpu_load',
                'severity' => 'critical',
                'event_time' => '2026-07-30T01:02:03+00:00',
                'resolved_by' => 'operator',
            ],
        ]);

        self::assertStringContainsString('снят вручную', $message['subject']);
        self::assertStringContainsString('edge-1', $message['subject']);
        self::assertStringContainsString('Снял: operator', $message['body']);
    }

    public function testTheServerLinkFollowsTheConfiguredPublicUrl(): void
    {
        $job = [
            'event_type' => 'metric_triggered',
            'payload' => [
                'server_id' => 7,
                'server_name' => 'edge-1',
                'metric' => 'cpu_load',
                'severity' => 'critical',
            ],
        ];

        self::assertStringContainsString(
            'Открыть сервер: https://monitor.example/servers/7',
            (new NotificationMessageFormatter('https://monitor.example/'))
                ->format($job)['body']
        );
        self::assertStringNotContainsString(
            'Открыть сервер',
            (new NotificationMessageFormatter())->format($job)['body']
        );
        self::assertStringNotContainsString(
            'Открыть сервер',
            (new NotificationMessageFormatter('https://monitor.example'))->format([
                'event_type' => 'test',
                'payload' => ['type' => 'test'],
            ])['body']
        );
    }
}
