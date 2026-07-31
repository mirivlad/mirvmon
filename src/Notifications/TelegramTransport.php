<?php

declare(strict_types=1);

namespace App\Notifications;

use Closure;
use JsonException;

final class TelegramTransport
{
    /** @var Closure(string, array<int, mixed>): array{status: int, body: string} */
    private readonly Closure $execute;

    /**
     * @param null|callable(string, array<int, mixed>): array{status: int, body: string} $execute
     */
    public function __construct(?callable $execute = null)
    {
        $this->execute = $execute === null
            ? self::defaultExecutor(...)
            : Closure::fromCallable($execute);
    }

    /** @param array<string, mixed> $settings */
    public function send(
        array $settings,
        string $subject,
        string $message
    ): void {
        $token = (string) ($settings['telegram_bot_token'] ?? '');
        $chatId = (string) ($settings['telegram_chat_id'] ?? '');
        if ($token === '' || $chatId === '') {
            throw new NotificationTransportException('telegram_not_configured');
        }

        try {
            $postBody = json_encode([
                'chat_id' => $chatId,
                'text' => sprintf(
                    "<b>%s</b>\n\n%s",
                    $this->escapeHtml($subject),
                    $this->escapeHtml($message)
                ),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new NotificationTransportException(
                'telegram_payload_invalid',
                0,
                $exception
            );
        }

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $this->configureProxy($options, $settings);

        $result = ($this->execute)(
            'https://api.telegram.org/bot' . $token . '/sendMessage',
            $options
        );
        if ($result['status'] !== 200) {
            throw new NotificationTransportException(
                'telegram_http_' . $result['status']
                . $this->apiDescription($result['body'])
            );
        }

        try {
            $response = json_decode(
                $result['body'],
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new NotificationTransportException(
                'telegram_response_invalid',
                0,
                $exception
            );
        }
        if (!is_array($response) || ($response['ok'] ?? false) !== true) {
            throw new NotificationTransportException('telegram_api_rejected');
        }
    }

    /**
     * Telegram explains a rejection in `description`. The bot token travels in
     * the URL, so the response body never carries a MirvMon secret.
     */
    private function apiDescription(string $body): string
    {
        $response = json_decode($body, true, 32);
        if (!is_array($response) || !is_string($response['description'] ?? null)) {
            return '';
        }

        return ': ' . $response['description'];
    }

    /**
     * @param array<int, mixed> $options
     * @param array<string, mixed> $settings
     */
    private function configureProxy(array &$options, array $settings): void
    {
        $type = $settings['telegram_proxy_type'] ?? null;
        if ($type === null || $type === '') {
            return;
        }

        $types = [
            'http' => CURLPROXY_HTTP,
            'https' => CURLPROXY_HTTPS,
            'socks4' => CURLPROXY_SOCKS4,
            'socks4a' => CURLPROXY_SOCKS4A,
            'socks5' => CURLPROXY_SOCKS5,
            'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
        ];
        if (!isset($types[$type])) {
            throw new NotificationTransportException('telegram_proxy_type_invalid');
        }

        $options[CURLOPT_PROXY] = (string) $settings['telegram_proxy_host'];
        $options[CURLOPT_PROXYPORT] = (int) $settings['telegram_proxy_port'];
        $options[CURLOPT_PROXYTYPE] = $types[$type];

        $username = (string) ($settings['telegram_proxy_username'] ?? '');
        $password = (string) ($settings['telegram_proxy_password'] ?? '');
        if ($username !== '') {
            $options[CURLOPT_PROXYUSERNAME] = $username;
        }
        if ($password !== '') {
            $options[CURLOPT_PROXYPASSWORD] = $password;
        }
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * @param array<int, mixed> $options
     * @return array{status: int, body: string}
     */
    private static function defaultExecutor(string $url, array $options): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new NotificationTransportException('telegram_client_init_failed');
        }
        if (!curl_setopt_array($curl, $options)) {
            throw new NotificationTransportException('telegram_client_config_failed');
        }

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (!is_string($body)) {
            // curl_strerror() returns a fixed message table, never the URL,
            // the proxy credentials or the bot token.
            throw new NotificationTransportException(
                'telegram_network_failed: ' . curl_strerror(curl_errno($curl))
            );
        }

        return ['status' => $status, 'body' => $body];
    }
}
