<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Security\SecretCipher;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class NotificationSettingsRepository
{
    private const PROXY_TYPES = [
        'http',
        'https',
        'socks4',
        'socks4a',
        'socks5',
        'socks5h',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretCipher $cipher
    ) {
    }

    /** @return array<string, mixed> */
    public function getPublic(): array
    {
        $settings = $this->row();
        $settings['has_smtp_password'] =
            $settings['smtp_password_encrypted'] !== null;
        $settings['has_telegram_bot_token'] =
            $settings['telegram_bot_token_encrypted'] !== null;
        $settings['has_telegram_proxy_password'] =
            $settings['telegram_proxy_password_encrypted'] !== null;
        unset(
            $settings['smtp_password_encrypted'],
            $settings['telegram_bot_token_encrypted'],
            $settings['telegram_proxy_password_encrypted']
        );
        $this->normalizeBooleans($settings);
        $this->decodeRecipients($settings);

        return $settings;
    }

    /** @return array<string, mixed> */
    public function getForDelivery(): array
    {
        $settings = $this->row();
        $settings['smtp_password'] = $this->decryptDatabaseValue(
            $settings['smtp_password_encrypted']
        );
        $settings['telegram_bot_token'] = $this->decryptDatabaseValue(
            $settings['telegram_bot_token_encrypted']
        );
        $settings['telegram_proxy_password'] = $this->decryptDatabaseValue(
            $settings['telegram_proxy_password_encrypted']
        );
        unset(
            $settings['smtp_password_encrypted'],
            $settings['telegram_bot_token_encrypted'],
            $settings['telegram_proxy_password_encrypted']
        );
        $this->normalizeBooleans($settings);
        $this->decodeRecipients($settings);

        return $settings;
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $normalized = $this->normalize($input, $this->row());
        $statement = $this->pdo->prepare(
            'UPDATE notification_settings SET
                smtp_host = :smtp_host,
                smtp_port = :smtp_port,
                smtp_username = :smtp_username,
                smtp_password_encrypted = :smtp_password_encrypted,
                smtp_encryption = :smtp_encryption,
                smtp_from_email = :smtp_from_email,
                smtp_recipients = CAST(:smtp_recipients AS jsonb),
                email_enabled = :email_enabled,
                telegram_bot_token_encrypted = :telegram_bot_token_encrypted,
                telegram_chat_id = :telegram_chat_id,
                telegram_enabled = :telegram_enabled,
                telegram_proxy_type = :telegram_proxy_type,
                telegram_proxy_host = :telegram_proxy_host,
                telegram_proxy_port = :telegram_proxy_port,
                telegram_proxy_username = :telegram_proxy_username,
                telegram_proxy_password_encrypted =
                    :telegram_proxy_password_encrypted,
                notify_on_warning = :notify_on_warning,
                notify_on_critical = :notify_on_critical,
                cooldown_seconds = :cooldown_seconds,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = 1'
        );
        foreach ($normalized as $column => $value) {
            // PDOStatement::execute() would bind booleans as strings, and
            // PostgreSQL rejects the empty string false becomes.
            $statement->bindValue(
                $column,
                $value,
                is_bool($value) ? PDO::PARAM_BOOL : PDO::PARAM_STR
            );
        }
        $statement->execute();
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function normalize(array $input, array $current): array
    {
        $emailEnabled = $this->toBool($input['email_enabled'] ?? false);
        $telegramEnabled = $this->toBool($input['telegram_enabled'] ?? false);
        $smtpHost = $this->nullableTrimmed($input['smtp_host'] ?? null);
        $smtpPort = $this->port($input['smtp_port'] ?? 587, 'SMTP');
        $smtpUsername = $this->nullableTrimmed($input['smtp_username'] ?? null);
        $smtpEncryption = (string) ($input['smtp_encryption'] ?? 'tls');
        $smtpFrom = $this->nullableTrimmed($input['smtp_from_email'] ?? null);
        $smtpRecipients = $this->emailList($input['smtp_recipients'] ?? null);
        if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
            throw new InvalidArgumentException('Unsupported SMTP encryption.');
        }
        if ($smtpHost !== null) {
            $this->validateHost($smtpHost, 'SMTP');
        }
        if ($smtpFrom !== null && filter_var($smtpFrom, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid SMTP sender email.');
        }
        if ($emailEnabled && ($smtpHost === null || $smtpFrom === null || $smtpRecipients === [])) {
            throw new InvalidArgumentException(
                'Enabled email notifications require SMTP host, sender and recipient.'
            );
        }

        $telegramChatId = $this->nullableTrimmed(
            $input['telegram_chat_id'] ?? null
        );
        $proxyType = $this->nullableTrimmed(
            $input['telegram_proxy_type'] ?? null
        );
        $proxyHost = $this->nullableTrimmed(
            $input['telegram_proxy_host'] ?? null
        );
        $proxyPort = $proxyType === null
            ? null
            : $this->port($input['telegram_proxy_port'] ?? null, 'Telegram proxy');
        $proxyUsername = $proxyType === null
            ? null
            : $this->nullableTrimmed($input['telegram_proxy_username'] ?? null);
        if ($proxyType !== null) {
            if (!in_array($proxyType, self::PROXY_TYPES, true)) {
                throw new InvalidArgumentException('Unsupported Telegram proxy type.');
            }
            if ($proxyHost === null) {
                throw new InvalidArgumentException('Telegram proxy host is required.');
            }
            $this->validateHost($proxyHost, 'Telegram proxy');
        } else {
            $proxyHost = null;
        }

        $smtpPassword = $this->secretValue(
            $input,
            'smtp_password',
            'clear_smtp_password',
            $current['smtp_password_encrypted']
        );
        $telegramToken = $this->secretValue(
            $input,
            'telegram_bot_token',
            'clear_telegram_bot_token',
            $current['telegram_bot_token_encrypted']
        );
        $proxyPassword = $proxyType === null
            ? null
            : $this->secretValue(
                $input,
                'telegram_proxy_password',
                'clear_telegram_proxy_password',
                $current['telegram_proxy_password_encrypted']
            );

        if ($telegramEnabled && ($telegramChatId === null || $telegramToken === null)) {
            throw new InvalidArgumentException(
                'Enabled Telegram notifications require bot token and chat ID.'
            );
        }

        return [
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_username' => $smtpUsername,
            'smtp_password_encrypted' => $smtpPassword,
            'smtp_encryption' => $smtpEncryption,
            'smtp_from_email' => $smtpFrom,
            'smtp_recipients' => json_encode($smtpRecipients, JSON_THROW_ON_ERROR),
            'email_enabled' => $emailEnabled,
            'telegram_bot_token_encrypted' => $telegramToken,
            'telegram_chat_id' => $telegramChatId,
            'telegram_enabled' => $telegramEnabled,
            'telegram_proxy_type' => $proxyType,
            'telegram_proxy_host' => $proxyHost,
            'telegram_proxy_port' => $proxyPort,
            'telegram_proxy_username' => $proxyUsername,
            'telegram_proxy_password_encrypted' => $proxyPassword,
            'cooldown_seconds' => $this->cooldown($input['cooldown_seconds'] ?? 0),
            'notify_on_warning' => $this->toBool(
                $input['notify_on_warning'] ?? false
            ),
            'notify_on_critical' => $this->toBool(
                $input['notify_on_critical'] ?? false
            ),
        ];
    }

    private function cooldown(mixed $value): int
    {
        $seconds = filter_var(
            $value === '' ? 0 : $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 86400]]
        );
        if ($seconds === false) {
            throw new InvalidArgumentException(
                'Пауза между одинаковыми уведомлениями — от 0 до 86400 секунд.'
            );
        }

        return $seconds;
    }

    /**
     * Accepts the comma or newline separated list the form submits.
     *
     * @return list<string>
     */
    private function emailList(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[,;\r\n]+/', (string) ($value ?? '')) ?: [];
        }

        $emails = [];
        foreach ($parts as $part) {
            $email = trim((string) $part);
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                throw new InvalidArgumentException('Invalid SMTP recipient email.');
            }
            if (!in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }
        if (count($emails) > 20) {
            throw new InvalidArgumentException('Too many SMTP recipients.');
        }

        return $emails;
    }

    /** @param array<string, mixed> $input */
    private function secretValue(
        array $input,
        string $field,
        string $clearField,
        mixed $current
    ): ?string {
        if ($this->toBool($input[$clearField] ?? false)) {
            return null;
        }
        $value = trim((string) ($input[$field] ?? ''));
        if ($value !== '') {
            if (strlen($value) > 8192) {
                throw new InvalidArgumentException('Secret is too long.');
            }
            return $this->cipher->encrypt($value);
        }

        return $this->databaseBytes($current);
    }

    private function validateHost(string $host, string $label): void
    {
        if (
            strlen($host) > 255
            || preg_match('/[\\s\\/?#@]/', $host) === 1
            || str_contains($host, '://')
        ) {
            throw new InvalidArgumentException($label . ' host is invalid.');
        }
    }

    private function port(mixed $value, string $label): int
    {
        $port = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        );
        if ($port === false) {
            throw new InvalidArgumentException($label . ' port is invalid.');
        }

        return $port;
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $statement = $this->pdo->query(
            'SELECT * FROM notification_settings WHERE id = 1'
        );
        $row = $statement?->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Notification settings row is missing.');
        }

        return $row;
    }

    private function decryptDatabaseValue(mixed $value): string
    {
        $bytes = $this->databaseBytes($value);

        return $bytes === null ? '' : $this->cipher->decrypt($bytes);
    }

    private function databaseBytes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if ($contents === false) {
                throw new RuntimeException('Cannot read encrypted database value.');
            }
            return $contents;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Invalid encrypted database value.');
        }
        if (str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));
            if ($decoded === false) {
                throw new RuntimeException('Invalid bytea database value.');
            }
            return $decoded;
        }

        return $value;
    }

    /** @param array<string, mixed> $settings */
    private function decodeRecipients(array &$settings): void
    {
        $decoded = json_decode((string) ($settings['smtp_recipients'] ?? '[]'), true);
        $recipients = [];
        if (is_array($decoded)) {
            foreach ($decoded as $email) {
                if (is_string($email) && $email !== '') {
                    $recipients[] = $email;
                }
            }
        }
        $settings['smtp_recipients'] = $recipients;
    }

    /** @param array<string, mixed> $settings */
    private function normalizeBooleans(array &$settings): void
    {
        foreach ([
            'email_enabled',
            'telegram_enabled',
            'notify_on_warning',
            'notify_on_critical',
        ] as $field) {
            $settings[$field] = $this->toBool($settings[$field]);
        }
    }

    private function toBool(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'on';
    }
}
