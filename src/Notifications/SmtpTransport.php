<?php

declare(strict_types=1);

namespace App\Notifications;

use Closure;

final class SmtpTransport
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
        $host = (string) ($settings['smtp_host'] ?? '');
        $from = (string) ($settings['smtp_from_email'] ?? '');
        $recipient = (string) ($settings['smtp_recipient_email'] ?? '');
        if ($host === '' || $from === '' || $recipient === '') {
            throw new NotificationTransportException('smtp_not_configured');
        }

        $port = (int) ($settings['smtp_port'] ?? 587);
        $encryption = (string) ($settings['smtp_encryption'] ?? 'tls');
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new NotificationTransportException('smtp_message_buffer_failed');
        }
        $email = $this->message($from, $recipient, $subject, $message);
        fwrite($stream, $email);
        rewind($stream);

        $options = [
            CURLOPT_MAIL_FROM => '<' . $from . '>',
            CURLOPT_MAIL_RCPT => ['<' . $recipient . '>'],
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => strlen($email),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USE_SSL => $encryption === 'none'
                ? CURLUSESSL_NONE
                : CURLUSESSL_ALL,
        ];

        $username = (string) ($settings['smtp_username'] ?? '');
        $password = (string) ($settings['smtp_password'] ?? '');
        if ($username !== '') {
            $options[CURLOPT_USERNAME] = $username;
        }
        if ($password !== '') {
            $options[CURLOPT_PASSWORD] = $password;
        }

        try {
            $result = ($this->execute)(
                sprintf('%s://%s:%d', $scheme, $host, $port),
                $options
            );
        } finally {
            fclose($stream);
        }

        if ($result['status'] < 200 || $result['status'] >= 400) {
            throw new NotificationTransportException(
                'smtp_response_' . $result['status']
            );
        }
    }

    private function message(
        string $from,
        string $recipient,
        string $subject,
        string $body
    ): string {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        return implode("\r\n", [
            'Date: ' . gmdate(DATE_RFC2822),
            'From: ' . $from,
            'To: ' . $recipient,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            str_replace(["\r\n", "\r"], "\n", $body),
            '',
        ]);
    }

    /**
     * @param array<int, mixed> $options
     * @return array{status: int, body: string}
     */
    private static function defaultExecutor(string $url, array $options): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new NotificationTransportException('smtp_client_init_failed');
        }
        if (!curl_setopt_array($curl, $options)) {
            throw new NotificationTransportException('smtp_client_config_failed');
        }

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            // curl_strerror() returns a fixed message table, never the host,
            // the mailbox or the SMTP password.
            throw new NotificationTransportException(
                'smtp_network_failed: ' . curl_strerror(curl_errno($curl))
            );
        }

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
        ];
    }
}
