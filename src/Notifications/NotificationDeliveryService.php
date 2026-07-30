<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Repositories\NotificationSettingsRepository;

final class NotificationDeliveryService
{
    public function __construct(
        private readonly NotificationSettingsRepository $settings,
        private readonly NotificationMessageFormatter $formatter,
        private readonly TelegramTransport $telegram,
        private readonly SmtpTransport $smtp
    ) {
    }

    /** @param array<string, mixed> $job */
    public function deliver(array $job): void
    {
        $settings = $this->settings->getForDelivery();
        $channel = (string) ($job['channel'] ?? '');
        $message = $this->formatter->format($job);

        if ($channel === 'telegram') {
            if ($settings['telegram_enabled'] !== true) {
                return;
            }
            $this->telegram->send(
                $settings,
                $message['subject'],
                $message['body']
            );
            return;
        }

        if ($channel === 'email') {
            if ($settings['email_enabled'] !== true) {
                return;
            }
            $this->smtp->send(
                $settings,
                $message['subject'],
                $message['body']
            );
            return;
        }

        throw new NotificationTransportException('notification_channel_unknown');
    }
}
