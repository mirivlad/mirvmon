<?php

declare(strict_types=1);

return [
    'server.page_title' => 'Сервер: {name}',
    'server.services.load' => 'Load',
    'server.services.active' => 'Active',
    'server.thresholds.disk' => 'Диск ({name})',
    'server.thresholds.network_in' => 'Сеть входящая ({name})',
    'server.thresholds.network_out' => 'Сеть исходящая ({name})',
    'server.js.services_error' => 'Ошибка получения списка сервисов',
    'server.js.total' => 'Всего',
    'server.js.used' => 'Занято',
    'server.js.free' => 'Свободно',

    'metric.hottest_sensor' => 'Самый горячий датчик',
    'metric.busiest_disk' => 'Самый занятый диск',
    'metric.busiest_interface' => 'Самый активный интерфейс',

    'notification.validation.smtp_encryption' => 'Выбран неподдерживаемый тип шифрования SMTP',
    'notification.validation.smtp_sender' => 'Укажите корректный адрес отправителя SMTP',
    'notification.validation.email_required' => 'Для Email-уведомлений укажите SMTP-сервер, адрес отправителя и хотя бы одного получателя',
    'notification.validation.proxy_type' => 'Выбран неподдерживаемый тип Telegram-прокси',
    'notification.validation.proxy_host_required' => 'Укажите хост Telegram-прокси',
    'notification.validation.telegram_required' => 'Для Telegram-уведомлений укажите токен бота и Chat ID',
    'notification.validation.cooldown' => 'Пауза уведомлений должна быть от 0 до 86400 секунд',
    'notification.validation.recipient' => 'Укажите корректный email получателя SMTP',
    'notification.validation.too_many_recipients' => 'Указано слишком много получателей SMTP',
    'notification.validation.secret_too_long' => 'Секретное значение слишком длинное',
    'notification.validation.smtp_host' => 'Укажите корректный SMTP-хост',
    'notification.validation.proxy_host' => 'Укажите корректный хост Telegram-прокси',
    'notification.validation.smtp_port' => 'Укажите корректный SMTP-порт',
    'notification.validation.proxy_port' => 'Укажите корректный порт Telegram-прокси',
];
