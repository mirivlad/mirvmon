<?php

declare(strict_types=1);

namespace App\Notifications;

final class NotificationMessageFormatter
{
    private readonly ?string $publicBaseUrl;

    /**
     * @param string $publicBaseUrl PUBLIC_BASE_URL. Empty when the deployment
     *                              did not configure one; messages then carry
     *                              no link, because a background worker has no
     *                              request to derive the origin from.
     */
    public function __construct(string $publicBaseUrl = '')
    {
        $publicBaseUrl = rtrim(trim($publicBaseUrl), '/');
        $this->publicBaseUrl = $publicBaseUrl === '' ? null : $publicBaseUrl;
    }

    /**
     * @param array<string, mixed> $job
     * @return array{subject: string, body: string}
     */
    public function format(array $job): array
    {
        $message = $this->message($job);
        $link = $this->serverLink(
            is_array($job['payload'] ?? null) ? $job['payload'] : []
        );
        if ($link !== null) {
            $message['body'] .= "\n" . $link;
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $job
     * @return array{subject: string, body: string}
     */
    private function message(array $job): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $eventType = (string) ($job['event_type'] ?? '');
        $server = $this->text($payload['server_name'] ?? 'unknown');
        $time = $this->text(
            $payload['event_time'] ?? $payload['sample_time'] ?? gmdate(DATE_ATOM)
        );

        if ($eventType === 'test') {
            return [
                'subject' => '✅ Тестовое уведомление MirvMon',
                'body' => implode("\n", [
                    'Канал уведомлений настроен и фоновый worker работает.',
                    'Время события: ' . $time,
                ]),
            ];
        }

        if ($eventType === 'alert_resolved') {
            $subject = $this->text($payload['subject'] ?? 'unknown');
            $lines = [
                'Сервер: ' . $server,
                'Объект: ' . $subject,
                'Серьёзность: ' . $this->text($payload['severity'] ?? 'warning'),
                'Время события: ' . $time,
            ];
            if (isset($payload['resolved_by'])) {
                $lines[] = 'Снял: ' . $this->text($payload['resolved_by']);
            }

            return [
                'subject' => sprintf(
                    '✅ Алерт снят вручную: %s / %s',
                    $server,
                    $subject
                ),
                'body' => implode("\n", $lines),
            ];
        }

        if (str_starts_with($eventType, 'metric_')) {
            $metric = $this->text(
                $payload['metric'] ?? $payload['metric_name'] ?? 'unknown'
            );
            $value = $this->text($payload['value'] ?? 'unknown');
            $severity = $this->text($payload['severity'] ?? 'warning');
            $recovered = $eventType === 'metric_recovered';
            $subject = $recovered
                ? sprintf('✅ Метрика восстановлена: %s / %s', $server, $metric)
                : sprintf(
                    '%s %s: %s / %s',
                    $severity === 'critical' ? '🚨' : '⚠️',
                    $severity === 'critical' ? 'Критическая тревога' : 'Предупреждение',
                    $server,
                    $metric
                );

            return [
                'subject' => $subject,
                'body' => implode("\n", [
                    'Сервер: ' . $server,
                    'Метрика: ' . $metric,
                    'Значение: ' . $value,
                    'Серьёзность: ' . $severity,
                    'Событие: ' . $this->text($payload['event'] ?? $eventType),
                    'Время события: ' . $time,
                ]),
            ];
        }

        if (str_starts_with($eventType, 'service_')) {
            $service = $this->text($payload['service'] ?? 'unknown');
            $recovered = $eventType === 'service_recovered';

            return [
                'subject' => $recovered
                    ? sprintf('✅ Сервис восстановлен: %s / %s', $server, $service)
                    : sprintf('🛑 Сервис остановлен: %s / %s', $server, $service),
                'body' => implode("\n", [
                    'Сервер: ' . $server,
                    'Сервис: ' . $service,
                    'Статус: ' . $this->text($payload['status'] ?? 'unknown'),
                    'Время события: ' . $time,
                ]),
            ];
        }

        if (str_starts_with($eventType, 'offline_')) {
            $recovered = $eventType === 'offline_recovered';
            $lines = [
                'Сервер: ' . $server,
                'Статус: ' . ($recovered ? 'online' : 'offline'),
                'Время события: ' . $time,
            ];
            if (!$recovered && isset($payload['last_metrics_at'])) {
                $lines[] = 'Последняя метрика: '
                    . $this->text($payload['last_metrics_at']);
            }

            return [
                'subject' => $recovered
                    ? '✅ Сервер восстановлен: ' . $server
                    : '🛑 Сервер недоступен: ' . $server,
                'body' => implode("\n", $lines),
            ];
        }

        return [
            'subject' => 'Уведомление MirvMon: ' . ($eventType ?: 'event'),
            'body' => implode("\n", [
                'Сервер: ' . $server,
                'Тип события: ' . ($eventType ?: 'unknown'),
                'Время события: ' . $time,
            ]),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function serverLink(array $payload): ?string
    {
        $serverId = filter_var(
            $payload['server_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($this->publicBaseUrl === null || $serverId === false) {
            return null;
        }

        return 'Открыть сервер: ' . $this->publicBaseUrl . '/servers/' . $serverId;
    }

    private function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return 'unknown';
    }
}
