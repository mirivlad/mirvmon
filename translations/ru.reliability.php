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
    'reliability.partial' => 'Неполный период',
    'reliability.distributed' => 'Распределённо: {points} точек · кворум {quorum}',
    'reliability.method' => 'Для серверов доступность рассчитана по переходам online/offline и показывается при полноте данных не ниже 95%. Для сайтов одна отчётная точка соответствует центральной автоматической проверке основного endpoint: transport оценивается с учётом выбранных удалённых точек и failure quorum, а assertions — только по Central MirvMon. Отсутствующая или устаревшая удалённая точка не считается отказом. При полноте ниже 95% результат помечается как неполный период. Инциденты могут пересекаться, поэтому их суммарная длительность не равна времени простоя.',
];
