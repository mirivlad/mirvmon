<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class RegistrationDomainNormalizer
{
    /** @var list<string>|null */
    private ?array $suffixes = null;

    public function __construct(private readonly string $publicSuffixListPath = '')
    {
    }

    public function normalize(string $input): string
    {
        $input = strtolower(rtrim(trim($input), '.'));
        if ($input === '' || str_contains($input, '/') || str_contains($input, ':')
            || filter_var($input, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Registration domain is invalid.');
        }
        $domain = idn_to_ascii($input, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($domain === false || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain)) {
            throw new InvalidArgumentException('Registration domain is invalid.');
        }
        $labels = explode('.', $domain);
        $suffix = $this->publicSuffix($labels);
        if ($suffix === null) {
            if (count($labels) !== 2) {
                throw new InvalidArgumentException('Registration domain must not be a subdomain.');
            }

            return $domain;
        }
        $suffixLabels = substr_count($suffix, '.') + 1;
        if (count($labels) <= $suffixLabels) {
            throw new InvalidArgumentException('Registration domain must not be a public suffix.');
        }
        $registrable = implode('.', array_slice($labels, -($suffixLabels + 1)));
        if ($domain !== $registrable) {
            throw new InvalidArgumentException('Registration domain must not be a subdomain.');
        }

        return $registrable;
    }

    /** @param list<string> $labels */
    private function publicSuffix(array $labels): ?string
    {
        $matches = [];
        $exceptions = [];
        $domain = implode('.', $labels);
        foreach ($this->suffixes() as $suffix) {
            if (str_starts_with($suffix, '!')) {
                $exception = substr($suffix, 1);
                if ($domain === $exception || str_ends_with($domain, '.' . $exception)) {
                    $exceptions[] = substr($exception, strpos($exception, '.') + 1);
                }
                continue;
            }
            if (str_starts_with($suffix, '*.')) {
                $tail = substr($suffix, 2);
                if (count($labels) > substr_count($tail, '.') + 1 && str_ends_with($domain, '.' . $tail)) {
                    $matches[] = implode('.', array_slice($labels, -(substr_count($tail, '.') + 2)));
                }
                continue;
            }
            if (implode('.', array_slice($labels, -(substr_count($suffix, '.') + 1))) === $suffix) {
                $matches[] = $suffix;
            }
        }
        if ($exceptions !== []) {
            usort($exceptions, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

            return $exceptions[0];
        }
        if ($matches === []) {
            return null;
        }
        usort($matches, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $matches[0];
    }

    /** @return list<string> */
    private function suffixes(): array
    {
        if ($this->suffixes !== null) {
            return $this->suffixes;
        }
        $path = $this->publicSuffixListPath !== ''
            ? $this->publicSuffixListPath
            : dirname(__DIR__, 2) . '/resources/domain/public_suffix_list.dat';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException('Public suffix list is unavailable.');
        }
        $suffixes = [];
        foreach (preg_split('/\R/', $contents) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '//')) {
                $suffixes[] = strtolower($line);
            }
        }
        if ($suffixes === []) {
            throw new InvalidArgumentException('Public suffix list is invalid.');
        }

        return $this->suffixes = $suffixes;
    }
}
