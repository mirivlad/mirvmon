<?php

declare(strict_types=1);

return [
    'version' => 1,
    'zones' => [
        'com' => [
            'server' => 'whois.verisign-grs.com:43',
            'patterns' => ['/^Registry Expiry Date:\s*(.+)$/mi'],
        ],
        'net' => [
            'server' => 'whois.verisign-grs.com:43',
            'patterns' => ['/^Registry Expiry Date:\s*(.+)$/mi'],
        ],
        'org' => [
            'server' => 'whois.publicinterestregistry.org:43',
            'patterns' => ['/^Expiration Date:\s*(.+)$/mi', '/^Expiry Date:\s*(.+)$/mi'],
        ],
        'ru' => [
            'server' => 'whois.tcinet.ru:43',
            'patterns' => ['/^paid-till:\s*(.+)$/mi'],
        ],
        'xn--p1ai' => [
            'server' => 'whois.tcinet.ru:43',
            'patterns' => ['/^paid-till:\s*(.+)$/mi'],
        ],
    ],
];
