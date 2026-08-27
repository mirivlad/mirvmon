<?php

declare(strict_types=1);

if ($argc !== 2) {
    exit(2);
}
$server = stream_socket_server('tcp://127.0.0.1:' . (int) $argv[1], $errorCode, $errorMessage);
if (!is_resource($server)) {
    exit(1);
}
stream_set_blocking($server, false);
$deadline = microtime(true) + 20.0;
$referralRequests = 0;
while (microtime(true) < $deadline) {
    $client = @stream_socket_accept($server, 1);
    if (is_resource($client)) {
        $domain = trim((string) fgets($client));
        if ($domain === '') {
            fclose($client);
            continue;
        }
        if ($domain === 'referral.test' && $referralRequests++ === 0) {
            fwrite($client, "Whois Server: 127.0.0.1:" . (int) $argv[1] . "\r\n");
        } else {
            fwrite($client, "Domain Name: EXAMPLE.TEST\r\nExpiration Date: 2027-03-04T05:06:07Z\r\n");
        }
        fclose($client);
    }
}
fclose($server);
