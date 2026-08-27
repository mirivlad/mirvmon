<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$query = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $query);

$respond = static function (int $status, string $body, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $body;
    exit;
};
$redirect = static function (string $location, int $status) use ($respond): never {
    $respond($status, '', ['Location' => $location]);
};

match ($path) {
    '/ok' => $respond(200, '<main id="health"><strong>OK</strong></main>'),
    '/redirect/start' => $redirect('/redirect/middle', 301),
    '/redirect/middle' => $redirect('/ok', 302),
    '/redirect-to' => $redirect((string) ($query['target'] ?? '/missing'), 302),
    '/loop-a' => $redirect('/loop-b', 302),
    '/loop-b' => $redirect('/loop-a', 302),
    '/status/503' => $respond(503, 'temporarily unavailable'),
    '/large' => $respond(200, str_repeat('x', 1048577)),
    '/slow' => (static function () use ($respond): never {
        usleep(250000);
        $respond(200, 'slow');
    })(),
    '/headers' => $respond(
        isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['HTTP_X_API_KEY']) ? 201 : 200,
        'headers checked'
    ),
    default => $respond(404, 'missing'),
};
