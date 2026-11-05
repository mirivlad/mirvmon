<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Notifications\ChimePortTransport;
use App\Security\SecretCipher;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * ChimePort is independent from SMTP/Telegram. NULL enabled means legacy
 * environment settings until the admin explicitly saves this web form.
 */
final class ChimePortSettingsRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretCipher $cipher,
    ) {
    }

    public static function enabled(mixed $databaseValue): bool
    {
        if ($databaseValue === null) {
            return ChimePortTransport::enabledFromEnvironment();
        }
        return in_array($databaseValue, [true, 1, '1', 't', 'on'], true);
    }

    /** @return array{enabled:bool,base_url:string,channel:string,has_token:bool,legacy:bool} */
    public function getPublic(): array
    {
        $row = $this->row();
        $legacy = $row['chimeport_enabled'] === null;
        $tokenFile = trim((string) getenv('CHIMEPORT_TOKEN_FILE'));
        $hasToken = $row['chimeport_token_encrypted'] !== null
            || ($legacy && $tokenFile !== '' && is_readable($tokenFile));
        return [
            'enabled' => self::enabled($row['chimeport_enabled']),
            'base_url' => $legacy ? trim((string) getenv('CHIMEPORT_BASE_URL')) : (string) ($row['chimeport_base_url'] ?? ''),
            'channel' => $legacy ? (trim((string) getenv('CHIMEPORT_CHANNEL')) ?: 'servers') : (string) ($row['chimeport_channel'] ?? 'servers'),
            'has_token' => $hasToken,
            'legacy' => $legacy && ChimePortTransport::enabledFromEnvironment(),
        ];
    }

    /** @return array{enabled:bool,base_url:string,channel:string,token:string} */
    public function getForDelivery(): array
    {
        $row = $this->row();
        $legacy = $row['chimeport_enabled'] === null;
        $token = '';
        if ($row['chimeport_token_encrypted'] !== null) {
            $token = $this->cipher->decrypt($this->databaseBytes($row['chimeport_token_encrypted']));
        } elseif ($legacy) {
            $token = $this->legacyToken();
        }
        return [
            'enabled' => self::enabled($row['chimeport_enabled']),
            'base_url' => $legacy ? trim((string) getenv('CHIMEPORT_BASE_URL')) : (string) ($row['chimeport_base_url'] ?? ''),
            'channel' => $legacy ? (trim((string) getenv('CHIMEPORT_CHANNEL')) ?: 'servers') : (string) ($row['chimeport_channel'] ?? 'servers'),
            'token' => $token,
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $row = $this->row();
        $enabled = in_array($input['chimeport_enabled'] ?? null, [true, 'on', '1', 1], true);
        $url = rtrim(trim((string) ($input['chimeport_base_url'] ?? '')), '/');
        $channel = trim((string) ($input['chimeport_channel'] ?? ''));
        if ($channel === '') {
            $channel = 'servers';
        }
        if (strlen($channel) > 80
            || !preg_match('~^[a-z0-9][a-z0-9_/-]{0,79}$~D', $channel)
            || str_contains($channel, '//')) {
            throw new InvalidArgumentException('ChimePort channel slug is invalid.');
        }
        if ($url !== '' && !$this->validUrl($url)) {
            throw new InvalidArgumentException('ChimePort requires a valid HTTPS server URL, without credentials, path or query.');
        }

        $clear = in_array($input['clear_chimeport_token'] ?? null, [true, 'on', '1', 1], true);
        $token = trim((string) ($input['chimeport_token'] ?? ''));
        $encrypted = null;
        if (!$clear) {
            if ($token !== '') {
                if (!$this->validToken($token)) {
                    throw new InvalidArgumentException('ChimePort source token is invalid.');
                }
                $encrypted = $this->cipher->encrypt($token);
            } elseif ($row['chimeport_token_encrypted'] !== null) {
                $encrypted = $this->databaseBytes($row['chimeport_token_encrypted']);
            } elseif ($row['chimeport_enabled'] === null) {
                // One-time migration: encrypt existing file credential inside DB.
                $legacyToken = $this->legacyToken();
                if ($this->validToken($legacyToken)) {
                    $encrypted = $this->cipher->encrypt($legacyToken);
                }
            }
        }
        if ($enabled && ($url === '' || $encrypted === null)) {
            throw new InvalidArgumentException('Enabled ChimePort requires an HTTPS server and source token.');
        }
        $query = $this->pdo->prepare(
            'UPDATE notification_settings SET
                chimeport_enabled = :enabled,
                chimeport_base_url = :url,
                chimeport_channel = :channel,
                chimeport_token_encrypted = :token,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = 1'
        );
        $query->bindValue('enabled', $enabled, PDO::PARAM_BOOL);
        $query->bindValue('url', $url === '' ? null : $url);
        $query->bindValue('channel', $channel);
        $query->bindValue('token', $encrypted, $encrypted === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $query->execute();
    }

    private function validUrl(string $url): bool
    {
        $parsed = parse_url($url);
        return is_array($parsed)
            && ($parsed['scheme'] ?? '') === 'https'
            && is_string($parsed['host'] ?? null)
            && $parsed['host'] !== ''
            && strlen($url) <= 512
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && !isset($parsed['user'])
            && !isset($parsed['pass'])
            && !isset($parsed['query'])
            && !isset($parsed['fragment'])
            && (!isset($parsed['path']) || $parsed['path'] === '' || $parsed['path'] === '/');
    }

    private function validToken(string $token): bool
    {
        return preg_match('/^cp_t_[A-Za-z0-9_-]{35,}$/D', $token) === 1 && strlen($token) <= 8192;
    }

    private function legacyToken(): string
    {
        $file = trim((string) getenv('CHIMEPORT_TOKEN_FILE'));
        if ($file === '') {
            return '';
        }
        $text = @file_get_contents($file, false, null, 0, 8193);
        return is_string($text) ? trim($text) : '';
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        $row = $this->pdo->query(
            'SELECT chimeport_enabled, chimeport_base_url, chimeport_channel,
                    chimeport_token_encrypted FROM notification_settings WHERE id=1'
        )?->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Notification settings row missing.');
        }
        return $row;
    }

    private function databaseBytes(mixed $value): string
    {
        if (is_resource($value)) {
            $read = stream_get_contents($value);
            if (!is_string($read)) {
                throw new RuntimeException('Cannot read encrypted credential.');
            }
            return $read;
        }
        if (is_string($value) && str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));
            if ($decoded === false) {
                throw new RuntimeException('Invalid stored credential.');
            }
            return $decoded;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Invalid stored credential.');
        }
        return $value;
    }
}
