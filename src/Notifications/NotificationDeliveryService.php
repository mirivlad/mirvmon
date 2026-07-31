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

    /**
     * @param array<string, mixed> $job
     * @return bool False when the channel is disabled and the job can never
     *              be delivered.
     */
    public function deliver(array $job): bool
    {
        $settings = $this->settings->getForDelivery();
        $channel = (string) ($job['channel'] ?? '');
        $message = $this->formatter->format($job);
        // The recipient was resolved when the job was queued, so a later
        // change of the global address does not redirect a pending alert.
        $recipient = $this->recipient($job);

        if ($channel === 'telegram') {
            if ($settings['telegram_enabled'] !== true) {
                return false;
            }
            $settings['telegram_chat_id'] = $recipient
                ?? $settings['telegram_chat_id'];
            $this->telegram->send(
                $settings,
                $message['subject'],
                $message['body']
            );
            return true;
        }

        if ($channel === 'email') {
            if ($settings['email_enabled'] !== true) {
                return false;
            }
            $recipients = is_array($settings['smtp_recipients'] ?? null)
                ? $settings['smtp_recipients']
                : [];
            $settings['smtp_recipient_email'] = $recipient ?? ($recipients[0] ?? '');
            $this->smtp->send(
                $settings,
                $message['subject'],
                $message['body']
            );
            return true;
        }

        throw new NotificationTransportException('notification_channel_unknown');
    }

    /** @param array<string, mixed> $job */
    private function recipient(array $job): ?string
    {
        $recipient = trim((string) ($job['recipient'] ?? ''));

        return $recipient === '' ? null : $recipient;
    }
}
