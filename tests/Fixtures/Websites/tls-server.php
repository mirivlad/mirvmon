<?php

declare(strict_types=1);

if ($argc !== 3) {
    exit(2);
}

$context = stream_context_create(['ssl' => [
    'local_cert' => $argv[2],
    'allow_self_signed' => true,
    'verify_peer' => false,
]]);
$server = stream_socket_server(
    'tls://127.0.0.1:' . (int) $argv[1],
    $errorCode,
    $errorMessage,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);
if (!is_resource($server)) {
    exit(1);
}
stream_set_blocking($server, false);
$deadline = microtime(true) + 20.0;
while (microtime(true) < $deadline) {
    $client = @stream_socket_accept($server, 1);
    if (is_resource($client)) {
        stream_set_timeout($client, 1);
        fread($client, 8192);
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK");
        fclose($client);
    }
}
fclose($server);
