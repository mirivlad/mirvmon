<?php

declare(strict_types=1);

namespace App\Repositories;

use JsonException;
use PDO;

final class AppSettingsRepository
{
    /** @var array<string, int> */
    public const WEBSITE_DEFAULTS = [
        'website_default_interval_seconds' => 60,
        'website_http_timeout_seconds' => 15,
        'website_tls_warning_days' => 21,
        'website_tls_critical_days' => 7,
        'website_domain_warning_days' => 30,
        'website_domain_critical_days' => 7,
        'website_worker_concurrency' => 10,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM app_settings WHERE setting_key = :key'
        );
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            return $default;
        }

        try {
            return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $default;
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [];
        foreach (array_values($keys) as $index => $key) {
            $placeholder = ':key_' . $index;
            $placeholders[] = $placeholder;
            $parameters['key_' . $index] = $key;
        }

        $statement = $this->pdo->prepare(
            'SELECT setting_key, setting_value FROM app_settings'
            . ' WHERE setting_key IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($parameters);

        $settings = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['setting_key'] ?? '');
            if ($key === '') {
                continue;
            }
            try {
                $settings[$key] = json_decode(
                    (string) ($row['setting_value'] ?? 'null'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                continue;
            }
        }

        return $settings;
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /** @return array<string, int> */
    public function websiteDefaults(): array
    {
        $stored = $this->getMany(array_keys(self::WEBSITE_DEFAULTS));
        $defaults = [];
        foreach (self::WEBSITE_DEFAULTS as $key => $fallback) {
            $value = $stored[$key] ?? $fallback;
            $defaults[$key] = is_int($value) ? $value : $fallback;
        }

        return $defaults;
    }

    /** @param array<string, mixed> $settings */
    public function setMany(array $settings): void
    {
        if ($settings === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (:key, CAST(:value AS jsonb))
            ON CONFLICT (setting_key) DO UPDATE
            SET setting_value = EXCLUDED.setting_value
            SQL
        );

        foreach ($settings as $key => $value) {
            $statement->execute([
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
            ]);
        }
    }
}
