<?php

declare(strict_types=1);

return [
    'server.page_title' => 'Server: {name}',
    'server.services.load' => 'Load',
    'server.services.active' => 'Active',
    'server.thresholds.disk' => 'Disk ({name})',
    'server.thresholds.network_in' => 'Inbound network ({name})',
    'server.thresholds.network_out' => 'Outbound network ({name})',
    'server.js.services_error' => 'Failed to fetch service list',
    'server.js.total' => 'Total',
    'server.js.used' => 'Used',
    'server.js.free' => 'Free',

    'metric.hottest_sensor' => 'Hottest sensor',
    'metric.busiest_disk' => 'Busiest disk',
    'metric.busiest_interface' => 'Most active interface',

    'notification.validation.smtp_encryption' => 'Unsupported SMTP encryption type',
    'notification.validation.smtp_sender' => 'Enter a valid SMTP sender address',
    'notification.validation.email_required' => 'Email notifications require an SMTP server, sender address and at least one recipient',
    'notification.validation.proxy_type' => 'Unsupported Telegram proxy type',
    'notification.validation.proxy_host_required' => 'Enter the Telegram proxy host',
    'notification.validation.telegram_required' => 'Telegram notifications require a bot token and Chat ID',
    'notification.validation.cooldown' => 'Notification cooldown must be between 0 and 86400 seconds',
    'notification.validation.recipient' => 'Enter a valid SMTP recipient email address',
    'notification.validation.too_many_recipients' => 'Too many SMTP recipients',
    'notification.validation.secret_too_long' => 'Secret value is too long',
    'notification.validation.smtp_host' => 'Enter a valid SMTP host',
    'notification.validation.proxy_host' => 'Enter a valid Telegram proxy host',
    'notification.validation.smtp_port' => 'Enter a valid SMTP port',
    'notification.validation.proxy_port' => 'Enter a valid Telegram proxy port',
];
