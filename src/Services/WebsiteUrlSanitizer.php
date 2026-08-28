<?php

declare(strict_types=1);

namespace App\Services;

final class WebsiteUrlSanitizer
{
    private const SENSITIVE_QUERY_KEY = '/(?:token|key|secret|password|auth|signature)/i';

    public function forDisplay(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $result = strtolower($parts['scheme']) . '://' . $this->formatHost($parts['host']);
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        $result .= $parts['path'] ?? '';

        if (isset($parts['query']) && $parts['query'] !== '') {
            $items = [];
            foreach (explode('&', $parts['query']) as $item) {
                [$rawKey, $rawValue] = array_pad(explode('=', $item, 2), 2, null);
                if (preg_match(self::SENSITIVE_QUERY_KEY, rawurldecode($rawKey)) === 1) {
                    $rawValue = rawurlencode('[redacted]');
                }
                $items[] = $rawValue === null ? $rawKey : $rawKey . '=' . $rawValue;
            }
            $result .= '?' . implode('&', $items);
        }

        return $result;
    }

    private function formatHost(string $host): string
    {
        $host = strtolower($host);

        return str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
    }
}
