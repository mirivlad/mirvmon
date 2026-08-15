<?php

declare(strict_types=1);

namespace App\I18n;

use App\Repositories\AppSettingsRepository;
use RuntimeException;
use Stringable;
use Throwable;

final class Translator
{
    public const DEFAULT_LOCALE = 'ru';

    /** @var list<string> */
    public const SUPPORTED_LOCALES = ['ru', 'en'];

    private string $locale = self::DEFAULT_LOCALE;

    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    public function __construct(
        private readonly AppSettingsRepository $settings,
        private readonly string $translationsPath
    ) {
    }

    public function refreshLocale(): void
    {
        try {
            $stored = $this->settings->get('ui_language', self::DEFAULT_LOCALE);
        } catch (Throwable) {
            $stored = self::DEFAULT_LOCALE;
        }

        $this->locale = is_string($stored) && $this->isSupported($stored)
            ? $stored
            : self::DEFAULT_LOCALE;
    }

    public function setLocale(string $locale): void
    {
        if (!$this->isSupported($locale)) {
            throw new RuntimeException('Unsupported locale: ' . $locale);
        }
        $this->locale = $locale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    /** @return array<string, string> */
    public function supportedLocales(): array
    {
        return [
            'ru' => 'Русский',
            'en' => 'English',
        ];
    }

    /**
     * @param array<string, scalar|Stringable|null> $parameters
     */
    public function trans(string $key, array $parameters = []): string
    {
        $catalog = $this->catalog($this->locale);
        $fallback = $this->locale === self::DEFAULT_LOCALE
            ? $catalog
            : $this->catalog(self::DEFAULT_LOCALE);

        $message = $catalog[$key] ?? $fallback[$key] ?? $key;
        if ($parameters === []) {
            return $message;
        }

        $replace = [];
        foreach ($parameters as $name => $value) {
            if ($value === null) {
                $rendered = '';
            } elseif (is_bool($value)) {
                $rendered = $value ? '1' : '0';
            } else {
                $rendered = (string) $value;
            }
            $replace['{' . $name . '}'] = $rendered;
        }

        return strtr($message, $replace);
    }

    /** @return array<string, string> */
    public function catalogFor(string $locale): array
    {
        if (!$this->isSupported($locale)) {
            throw new RuntimeException('Unsupported locale: ' . $locale);
        }

        return $this->catalog($locale);
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }

        $path = rtrim($this->translationsPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $locale . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('Translation catalog not found: ' . $locale);
        }

        $catalog = require $path;
        if (!is_array($catalog)) {
            throw new RuntimeException('Invalid translation catalog: ' . $locale);
        }

        $normalized = [];
        foreach ($catalog as $key => $message) {
            if (!is_string($key) || !is_string($message)) {
                throw new RuntimeException('Translation catalog must contain string keys and values.');
            }
            $normalized[$key] = $message;
        }

        return $this->catalogs[$locale] = $normalized;
    }
}
