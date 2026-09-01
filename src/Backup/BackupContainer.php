<?php

declare(strict_types=1);

namespace App\Backup;

use JsonException;
use RuntimeException;
use Throwable;

final class BackupContainer
{
    private const MAGIC = "MIRVMONBK1\n";
    private const FORMAT_VERSION = 1;
    private const AAD = 'MIRVMON-BACKUP-DEK-v1';
    private const CHUNK_BYTES = 65536;
    private const MAX_HEADER_BYTES = 16384;
    private const MAX_FRAME_BYTES = 131072;
    private const RECORD_START = "\x01";
    private const RECORD_DATA = "\x02";
    private const RECORD_END = "\x03";
    private const PAYLOAD_END = "\x04";
    private const ALLOWED_RECORDS = [
        'manifest.json',
        'database.pgdump',
        'secrets.json',
    ];

    public function __construct(
        private readonly int $opslimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        private readonly int $memlimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE
    ) {
        if ($this->opslimit < SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE) {
            throw new RuntimeException('Backup KDF opslimit is too low.');
        }
        if ($this->memlimit < SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE) {
            throw new RuntimeException('Backup KDF memlimit is too low.');
        }
    }

    /**
     * @param array<string, string> $records map of fixed record name to source file path
     */
    public function write(string $destination, string $password, array $records): void
    {
        $this->assertPassword($password);
        $this->assertRecords($records);

        $directory = dirname($destination);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Backup destination directory is not writable.');
        }

        $temporary = $destination . '.tmp-' . bin2hex(random_bytes(6));
        $output = @fopen($temporary, 'xb');
        if ($output === false) {
            throw new RuntimeException('Cannot create temporary backup file.');
        }
        @chmod($temporary, 0600);

        try {
            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $kek = $this->deriveKey($password, $salt, $this->opslimit, $this->memlimit);
            $dek = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
            $wrapNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $wrappedDek = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $dek,
                self::AAD,
                $wrapNonce,
                $kek
            );
            [$streamState, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);

            $header = $this->encodeHeader([
                'format' => self::FORMAT_VERSION,
                'kdf' => 'argon2id13',
                'opslimit' => $this->opslimit,
                'memlimit' => $this->memlimit,
                'salt' => base64_encode($salt),
                'wrap' => 'xchacha20poly1305-ietf',
                'wrap_nonce' => base64_encode($wrapNonce),
                'wrapped_dek' => base64_encode($wrappedDek),
                'stream' => 'secretstream-xchacha20poly1305',
                'stream_header' => base64_encode($streamHeader),
            ]);

            $this->writeAll($output, self::MAGIC);
            $this->writeAll($output, pack('N', strlen($header)));
            $this->writeAll($output, $header);

            foreach (self::ALLOWED_RECORDS as $name) {
                $source = $records[$name];
                $input = @fopen($source, 'rb');
                if ($input === false) {
                    throw new RuntimeException('Cannot open backup record: ' . $name);
                }
                try {
                    $this->writeEncryptedFrame($output, $streamState, self::RECORD_START . $name);
                    while (!feof($input)) {
                        $chunk = fread($input, self::CHUNK_BYTES);
                        if ($chunk === false) {
                            throw new RuntimeException('Cannot read backup record: ' . $name);
                        }
                        if ($chunk !== '') {
                            $this->writeEncryptedFrame($output, $streamState, self::RECORD_DATA . $chunk);
                        }
                    }
                    $this->writeEncryptedFrame($output, $streamState, self::RECORD_END);
                } finally {
                    fclose($input);
                }
            }

            $this->writeEncryptedFrame(
                $output,
                $streamState,
                self::PAYLOAD_END,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
            );
            fflush($output);
            fclose($output);
            $output = null;

            if (!@rename($temporary, $destination)) {
                throw new RuntimeException('Cannot publish completed backup file.');
            }
            @chmod($destination, 0600);
        } catch (Throwable $exception) {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
            throw $exception;
        }
    }

    /**
     * Extracts fixed records into $destinationDirectory and returns decoded plaintext header.
     *
     * @return array<string, mixed>
     */
    public function extract(string $backupPath, string $password, string $destinationDirectory): array
    {
        $this->assertPassword($password);
        if (!is_dir($destinationDirectory) || !is_writable($destinationDirectory)) {
            throw new RuntimeException('Backup extraction directory is not writable.');
        }

        $input = @fopen($backupPath, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot open backup file.');
        }

        $created = [];
        $current = null;
        $currentName = null;
        try {
            if ($this->readExact($input, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Invalid MirvMon backup magic.');
            }
            $headerLengthRaw = $this->readExact($input, 4);
            $headerLength = unpack('Nlength', $headerLengthRaw)['length'] ?? 0;
            if (!is_int($headerLength) || $headerLength < 2 || $headerLength > self::MAX_HEADER_BYTES) {
                throw new RuntimeException('Invalid MirvMon backup header length.');
            }
            $header = $this->decodeHeader($this->readExact($input, $headerLength));
            $this->validateHeader($header);

            $salt = $this->decodeBase64Field($header, 'salt', SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $wrapNonce = $this->decodeBase64Field(
                $header,
                'wrap_nonce',
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            );
            $wrappedDek = $this->decodeBase64Field($header, 'wrapped_dek');
            $streamHeader = $this->decodeBase64Field(
                $header,
                'stream_header',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
            );
            $kek = $this->deriveKey(
                $password,
                $salt,
                (int) $header['opslimit'],
                (int) $header['memlimit']
            );
            $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $wrappedDek,
                self::AAD,
                $wrapNonce,
                $kek
            );
            if ($dek === false) {
                throw new RuntimeException('Invalid backup password or corrupted backup header.');
            }
            $streamState = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
                $streamHeader,
                $dek
            );

            $seen = [];
            $finished = false;
            while (!$finished) {
                $lengthRaw = fread($input, 4);
                if ($lengthRaw === false || strlen($lengthRaw) !== 4) {
                    throw new RuntimeException('Unexpected end of encrypted backup payload.');
                }
                $frameLength = unpack('Nlength', $lengthRaw)['length'] ?? 0;
                if (!is_int($frameLength) || $frameLength < 1 || $frameLength > self::MAX_FRAME_BYTES) {
                    throw new RuntimeException('Invalid encrypted backup frame length.');
                }
                $ciphertext = $this->readExact($input, $frameLength);
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($streamState, $ciphertext);
                if ($pulled === false) {
                    throw new RuntimeException('Backup payload authentication failed.');
                }
                [$plaintext, $tag] = $pulled;
                if ($plaintext === '') {
                    throw new RuntimeException('Empty backup payload frame.');
                }

                $type = $plaintext[0];
                $payload = substr($plaintext, 1);
                if ($type === self::RECORD_START) {
                    if ($current !== null || !in_array($payload, self::ALLOWED_RECORDS, true) || isset($seen[$payload])) {
                        throw new RuntimeException('Invalid backup record sequence.');
                    }
                    $currentName = $payload;
                    $path = $destinationDirectory . DIRECTORY_SEPARATOR . $payload;
                    $current = @fopen($path, 'xb');
                    if ($current === false) {
                        throw new RuntimeException('Cannot create extracted backup record.');
                    }
                    @chmod($path, 0600);
                    $created[] = $path;
                    $seen[$payload] = true;
                    continue;
                }
                if ($type === self::RECORD_DATA) {
                    if (!is_resource($current) || $currentName === null || $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                        throw new RuntimeException('Invalid backup data frame sequence.');
                    }
                    $this->writeAll($current, $payload);
                    continue;
                }
                if ($type === self::RECORD_END) {
                    if (!is_resource($current) || $payload !== '' || $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                        throw new RuntimeException('Invalid backup record terminator.');
                    }
                    fclose($current);
                    $current = null;
                    $currentName = null;
                    continue;
                }
                if ($type === self::PAYLOAD_END) {
                    if (
                        $payload !== ''
                        || $current !== null
                        || $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    ) {
                        throw new RuntimeException('Invalid backup payload terminator.');
                    }
                    $finished = true;
                    continue;
                }
                throw new RuntimeException('Unknown backup payload frame type.');
            }

            if (array_keys($seen) !== self::ALLOWED_RECORDS) {
                throw new RuntimeException('Backup payload is missing required records.');
            }
            if (fread($input, 1) !== '') {
                throw new RuntimeException('Unexpected bytes after backup payload.');
            }

            fclose($input);
            return $header;
        } catch (Throwable $exception) {
            if (is_resource($current)) {
                fclose($current);
            }
            fclose($input);
            foreach ($created as $path) {
                @unlink($path);
            }
            throw $exception;
        }
    }

    /** @param resource $output */
    private function writeEncryptedFrame($output, string &$state, string $plaintext, int $tag = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE): void
    {
        $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plaintext, '', $tag);
        $this->writeAll($output, pack('N', strlen($ciphertext)));
        $this->writeAll($output, $ciphertext);
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write backup data.');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($stream, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Unexpected end of backup file.');
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    private function deriveKey(string $password, string $salt, int $opslimit, int $memlimit): string
    {
        if (
            $opslimit < SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE
            || $opslimit > SODIUM_CRYPTO_PWHASH_OPSLIMIT_SENSITIVE
            || $memlimit < SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
            || $memlimit > SODIUM_CRYPTO_PWHASH_MEMLIMIT_SENSITIVE
        ) {
            throw new RuntimeException('Backup KDF parameters are outside allowed bounds.');
        }

        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $password,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    /** @param array<string, string> $records */
    private function assertRecords(array $records): void
    {
        if (array_keys($records) !== self::ALLOWED_RECORDS) {
            throw new RuntimeException('Backup records must use the fixed v1 record set and order.');
        }
        foreach ($records as $name => $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException('Backup record is not readable: ' . $name);
            }
        }
    }

    private function assertPassword(string $password): void
    {
        if (strlen($password) < 8 || strlen($password) > 1024) {
            throw new RuntimeException('Backup password must contain between 8 and 1024 bytes.');
        }
    }

    /** @param array<string, mixed> $header */
    private function encodeHeader(array $header): string
    {
        try {
            return json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode backup header.', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function decodeHeader(string $encoded): array
    {
        try {
            $header = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid backup header JSON.', 0, $exception);
        }
        if (!is_array($header)) {
            throw new RuntimeException('Invalid backup header.');
        }
        return $header;
    }

    /** @param array<string, mixed> $header */
    private function validateHeader(array $header): void
    {
        $expectedStrings = [
            'kdf' => 'argon2id13',
            'wrap' => 'xchacha20poly1305-ietf',
            'stream' => 'secretstream-xchacha20poly1305',
        ];
        if (($header['format'] ?? null) !== self::FORMAT_VERSION) {
            throw new RuntimeException('Unsupported MirvMon backup format version.');
        }
        foreach ($expectedStrings as $key => $expected) {
            if (($header[$key] ?? null) !== $expected) {
                throw new RuntimeException('Unsupported backup crypto suite.');
            }
        }
        if (!is_int($header['opslimit'] ?? null) || !is_int($header['memlimit'] ?? null)) {
            throw new RuntimeException('Invalid backup KDF parameters.');
        }
        $this->deriveKeyBoundsOnly((int) $header['opslimit'], (int) $header['memlimit']);
    }

    private function deriveKeyBoundsOnly(int $opslimit, int $memlimit): void
    {
        if (
            $opslimit < SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE
            || $opslimit > SODIUM_CRYPTO_PWHASH_OPSLIMIT_SENSITIVE
            || $memlimit < SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
            || $memlimit > SODIUM_CRYPTO_PWHASH_MEMLIMIT_SENSITIVE
        ) {
            throw new RuntimeException('Backup KDF parameters are outside allowed bounds.');
        }
    }

    /** @param array<string, mixed> $header */
    private function decodeBase64Field(array $header, string $field, ?int $expectedBytes = null): string
    {
        $encoded = $header[$field] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Invalid backup header field: ' . $field);
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || ($expectedBytes !== null && strlen($decoded) !== $expectedBytes)) {
            throw new RuntimeException('Invalid backup header field: ' . $field);
        }
        return $decoded;
    }
}
