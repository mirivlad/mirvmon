<?php

declare(strict_types=1);

return [
    'reliability.analytics' => 'Аналитика',
    'reliability.title' => 'Отчёт о надёжности',
    'reliability.subtitle' => 'Доступность, полнота наблюдений и инциденты',
    'reliability.window_note' => 'время отображается в часовом поясе интерфейса',
    'reliability.servers' => 'Серверы',
    'reliability.websites' => 'Сайты',
    'reliability.empty' => 'Активных объектов нет.',
    'reliability.availability' => 'Доступность',
    'reliability.coverage' => 'Полнота наблюдений',
    'reliability.downtime' => 'Простой',
    'reliability.incidents' => 'Инциденты',
    'reliability.duration' => 'Время инцидентов',
    'reliability.recovery' => 'Среднее время восстановления',
    'reliability.insufficient' => 'Недостаточно данных',
    'reliability.method' => 'Для серверов доступность рассчитана по переходам online/offline, для сайтов — по успешным автоматическим проверкам основного endpoint, включая проверки содержимого. Полнота — отношение полученных измерений к ожидаемым при текущем интервале. При полноте ниже 95% доступность скрыта. Инциденты могут пересекаться, поэтому их суммарная длительность не равна времени простоя.',
];
