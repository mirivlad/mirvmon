<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Who a single event goes to. A server may override the installation-wide
 * addresses, which is how one machine becomes one person's problem.
 */
final class NotificationRecipients
{
    /**
     * @param list<string> $emails
     */
    public function __construct(
        public readonly ?string $telegramChatId,
        public readonly array $emails
    ) {
    }

    public function hasTelegram(): bool
    {
        return $this->telegramChatId !== null && $this->telegramChatId !== '';
    }

    /** @return list<string> */
    public function emails(): array
    {
        return $this->emails;
    }
}
