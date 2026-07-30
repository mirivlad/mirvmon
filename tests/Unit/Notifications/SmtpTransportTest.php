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
}
