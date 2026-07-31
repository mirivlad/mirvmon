<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\SmtpTransport;
use PHPUnit\Framework\TestCase;

final class SmtpTransportTest extends TestCase
{
    public function testStartTlsSmtpUsesVerifiedTlsAndAuthentication(): void
    {
        $capturedUrl = '';
        $capturedOptions = [];
        $transport = new SmtpTransport(
            static function (string $url, array $options) use (
                &$capturedUrl,
                &$capturedOptions
            ): array {
                $capturedUrl = $url;
                $capturedOptions = $options;
                return ['status' => 250, 'body' => ''];
            }
        );

        $transport->send(
            [
                'smtp_host' => 'smtp.example.net',
                'smtp_port' => 587,
                'smtp_username' => 'monitor@example.net',
                'smtp_password' => 'smtp-secret',
                'smtp_encryption' => 'tls',
                'smtp_from_email' => 'monitor@example.net',
                'smtp_recipient_email' => 'ops@example.net',
            ],
            'Alert subject',
            'Alert body'
        );

        self::assertSame('smtp://smtp.example.net:587', $capturedUrl);
        self::assertSame(CURLUSESSL_ALL, $capturedOptions[CURLOPT_USE_SSL]);
        self::assertSame('monitor@example.net', $capturedOptions[CURLOPT_USERNAME]);
        self::assertSame('smtp-secret', $capturedOptions[CURLOPT_PASSWORD]);
        self::assertTrue($capturedOptions[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $capturedOptions[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(
            ['<ops@example.net>'],
            $capturedOptions[CURLOPT_MAIL_RCPT]
        );
    }

    public function testAChartIsAttachedAsAMultipartPngWithoutTouchingTheText(): void
    {
        $captured = null;
        $transport = new SmtpTransport(
            static function (string $url, array $options) use (&$captured): array {
                rewind($options[CURLOPT_INFILE]);
                $captured = stream_get_contents($options[CURLOPT_INFILE]);

                return ['status' => 250, 'body' => ''];
            }
        );

        $transport->send(
            [
                'smtp_host' => 'smtp.example.net',
                'smtp_from_email' => 'monitor@example.net',
                'smtp_recipient_email' => 'ops@example.net',
            ],
            'Тревога',
            'Сервер: edge-1',
            "\x89PNG\r\n\x1a\nfake"
        );

        self::assertIsString($captured);
        self::assertStringContainsString('Content-Type: multipart/mixed; boundary="mirvmon-', $captured);
        self::assertStringContainsString('Content-Type: image/png; name="metric.png"', $captured);
        self::assertStringContainsString('Content-Transfer-Encoding: base64', $captured);
        self::assertStringContainsString(base64_encode("\x89PNG\r\n\x1a\nfake"), $captured);
        self::assertStringContainsString('Сервер: edge-1', $captured);
    }

    public function testWithoutAChartTheMessageStaysPlainText(): void
    {
        $captured = null;
        $transport = new SmtpTransport(
            static function (string $url, array $options) use (&$captured): array {
                rewind($options[CURLOPT_INFILE]);
                $captured = stream_get_contents($options[CURLOPT_INFILE]);

                return ['status' => 250, 'body' => ''];
            }
        );

        $transport->send(
            [
                'smtp_host' => 'smtp.example.net',
                'smtp_from_email' => 'monitor@example.net',
                'smtp_recipient_email' => 'ops@example.net',
            ],
            'Тревога',
            'Сервер: edge-1'
        );

        self::assertIsString($captured);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $captured);
        self::assertStringNotContainsString('multipart', $captured);
    }
}
