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
    'reliability.distributed' => 'Distributed: {points} points · quorum {quorum}',
    'reliability.method' => 'Server availability is based on online/offline transitions and is shown when data coverage is at least 95%. For websites, each report observation is a central automatic check of the primary endpoint: transport is evaluated with the selected remote points and failure quorum, while assertions remain Central MirvMon-only. Missing or stale remote data does not count as failure. Below 95% coverage the result is marked as a partial period. Incidents may overlap, so their total duration is not downtime.',
];
