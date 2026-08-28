<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$respond = static function (int $status, string $body, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $body;
    exit;
};

match ($path) {
    '/specific/domain/example.com' => $respond(200, json_encode([
        'events' => [['eventAction' => 'expiration', 'eventDate' => '2028-02-16T12:00:00Z']],
        'entities' => [[
            'roles' => ['registrar'],
            'vcardArray' => ['vcard', [['fn', [], 'text', 'Specific Registrar']]],
        ]],
    ], JSON_THROW_ON_ERROR), ['Content-Type' => 'application/rdap+json']),
    '/rdap/domain/example.com' => $respond(200, json_encode([
        'events' => [['eventAction' => 'expiration', 'eventDate' => '2027-01-15T12:00:00Z']],
        'entities' => [[
            'roles' => ['registrar'],
            'vcardArray' => ['vcard', [['fn', [], 'text', 'Example Registrar']]],
        ]],
    ], JSON_THROW_ON_ERROR), ['Content-Type' => 'application/rdap+json']),
    '/rdap/domain/rate.com' => $respond(429, '', ['Retry-After' => '120']),
    '/rdap/domain/malformed.com' => $respond(200, '{bad json', ['Content-Type' => 'application/rdap+json']),
    default => $respond(404, 'missing'),
};
