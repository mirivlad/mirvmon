<?php

declare(strict_types=1);

return [
    'reliability.analytics' => 'Analytics',
    'reliability.title' => 'Reliability report',
    'reliability.subtitle' => 'Availability, observation coverage, and incidents',
    'reliability.window_note' => 'times use the interface time zone',
    'reliability.servers' => 'Servers',
    'reliability.websites' => 'Websites',
    'reliability.empty' => 'No active objects.',
    'reliability.availability' => 'Availability',
    'reliability.coverage' => 'Observation coverage',
    'reliability.downtime' => 'Downtime',
    'reliability.incidents' => 'Incidents',
    'reliability.duration' => 'Incident duration',
    'reliability.recovery' => 'Mean time to recovery',
    'reliability.insufficient' => 'Insufficient data',
    'reliability.partial' => 'Partial period',
    'reliability.method' => 'Server availability is based on online/offline transitions and is shown when data coverage is at least 95%. Website availability is based on successful automatic checks of the primary endpoint, including content assertions, and is shown for the observed data; below 95% coverage it is marked as a partial period. Coverage compares received measurements with those expected at the current interval. Incidents may overlap, so their total duration is not downtime.',
];
