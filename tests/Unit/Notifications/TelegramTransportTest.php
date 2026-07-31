<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\TelegramTransport;
use CURLStringFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramTransportTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function proxyTypes(): iterable
    {
        yield 'http' => ['http', CURLPROXY_HTTP];
        yield 'https' => ['https', CURLPROXY_HTTPS];
        yield 'socks4' => ['socks4', CURLPROXY_SOCKS4];
        yield 'socks4a' => ['socks4a', CURLPROXY_SOCKS4A];
        yield 'socks5' => ['socks5', CURLPROXY_SOCKS5];
        yield 'socks5h' => ['socks5h', CURLPROXY_SOCKS5_HOSTNAME];
    }

    #[DataProvider('proxyTypes')]
    public function testProxySettingsUseDedicatedCurlOptions(
        string $type,
        int $expectedCurlType
    ): void {
        $capturedUrl = '';
        $capturedOptions = [];
        $transport = new TelegramTransport(
            static function (string $url, array $options) use (
                &$capturedUrl,
                &$capturedOptions
            ): array {
                $capturedUrl = $url;
                $capturedOptions = $options;
                return ['status' => 200, 'body' => '{"ok":true}'];
            }
        );

        $transport->send(
            [
                'telegram_bot_token' => '123:secret',
                'telegram_chat_id' => '-100123',
                'telegram_proxy_type' => $type,
                'telegram_proxy_host' => 'proxy.internal',
                'telegram_proxy_port' => 1080,
                'telegram_proxy_username' => 'proxy-user',
                'telegram_proxy_password' => 'proxy-password',
            ],
            '<unsafe subject>',
            'status <critical>'
        );

        self::assertSame(
            'https://api.telegram.org/bot123:secret/sendMessage',
            $capturedUrl
        );
        self::assertSame('proxy.internal', $capturedOptions[CURLOPT_PROXY]);
        self::assertSame(1080, $capturedOptions[CURLOPT_PROXYPORT]);
        self::assertSame($expectedCurlType, $capturedOptions[CURLOPT_PROXYTYPE]);
        self::assertSame(
            'proxy-user',
            $capturedOptions[CURLOPT_PROXYUSERNAME]
        );
        self::assertSame(
            'proxy-password',
            $capturedOptions[CURLOPT_PROXYPASSWORD]
        );
        self::assertStringNotContainsString(
            'proxy-password',
            (string) $capturedOptions[CURLOPT_PROXY]
        );
        self::assertTrue($capturedOptions[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $capturedOptions[CURLOPT_SSL_VERIFYHOST]);

        $body = json_decode(
            (string) $capturedOptions[CURLOPT_POSTFIELDS],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('HTML', $body['parse_mode']);
        self::assertStringContainsString('&lt;unsafe subject&gt;', $body['text']);
        self::assertStringContainsString('status &lt;critical&gt;', $body['text']);
    }

    public function testDirectConnectionDoesNotSetProxyOptions(): void
    {
        $capturedOptions = [];
        $transport = new TelegramTransport(
            static function (string $url, array $options) use (&$capturedOptions): array {
                $capturedOptions = $options;
                return ['status' => 200, 'body' => '{"ok":true}'];
            }
        );

        $transport->send(
            [
                'telegram_bot_token' => '123:secret',
                'telegram_chat_id' => '42',
                'telegram_proxy_type' => null,
                'telegram_proxy_host' => null,
                'telegram_proxy_port' => null,
                'telegram_proxy_username' => null,
                'telegram_proxy_password' => null,
            ],
            'subject',
            'message'
        );

        self::assertArrayNotHasKey(CURLOPT_PROXY, $capturedOptions);
        self::assertArrayNotHasKey(CURLOPT_PROXYTYPE, $capturedOptions);
    }

    public function testAChartIsSentAsAPhotoWithTheTextAsCaption(): void
    {
        $url = null;
        $options = null;
        $transport = new TelegramTransport(
            static function (string $requestUrl, array $requestOptions) use (&$url, &$options): array {
                $url = $requestUrl;
                $options = $requestOptions;

                return ['status' => 200, 'body' => '{"ok":true}'];
            }
        );

        $transport->send(
            ['telegram_bot_token' => '123:token', 'telegram_chat_id' => '-100'],
            'Критическая тревога',
            'Сервер: edge-1',
            "\x89PNG\r\n\x1a\nfake"
        );

        self::assertSame('https://api.telegram.org/bot123:token/sendPhoto', $url);
        self::assertIsArray($options[CURLOPT_POSTFIELDS]);
        self::assertInstanceOf(
            CURLStringFile::class,
            $options[CURLOPT_POSTFIELDS]['photo']
        );
        self::assertStringContainsString(
            'Критическая тревога',
            $options[CURLOPT_POSTFIELDS]['caption']
        );
        self::assertSame('-100', $options[CURLOPT_POSTFIELDS]['chat_id']);
    }

    public function testWithoutAChartTheMessageGoesThroughSendMessage(): void
    {
        $url = null;
        $transport = new TelegramTransport(
            static function (string $requestUrl) use (&$url): array {
                $url = $requestUrl;

                return ['status' => 200, 'body' => '{"ok":true}'];
            }
        );

        $transport->send(
            ['telegram_bot_token' => '123:token', 'telegram_chat_id' => '-100'],
            'Тревога',
            'Сервер: edge-1'
        );

        self::assertSame('https://api.telegram.org/bot123:token/sendMessage', $url);
    }
}
