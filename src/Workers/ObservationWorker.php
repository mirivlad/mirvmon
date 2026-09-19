<?php

declare(strict_types=1);

namespace App\Workers;

use App\Repositories\NotificationOutboxRepository;
use App\Repositories\ObservationAnalysisRepository;
use App\Repositories\ObservationRepository;
use App\Services\ContextualLevelShiftAnalyzer;
use App\Services\DiskMetricAliasResolver;
use App\Services\ObservationAnalyzer;
use DateTimeImmutable;

final class ObservationWorker
{
    public function __construct(
        private readonly ObservationAnalysisRepository $analysis,
        private readonly ObservationRepository $observations,
        private readonly ObservationAnalyzer $analyzer,
        private readonly ContextualLevelShiftAnalyzer $levelAnalyzer,
        private readonly DiskMetricAliasResolver $diskAliases,
        private readonly NotificationOutboxRepository $outbox
    ) {
    }

    /** @return array{detected:int,notified:int,resolved:int} */
    public function runOnce(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $seen = [];
        $evaluatedMetrics = [];
        $detected = 0;
        $notified = 0;
        $resolvedEpisodes = 0;

        $approvedPatterns = [];
        foreach ($this->analysis->approvedLevelPatterns() as $pattern) {
            $key = $this->metricKey((int) $pattern['server_id'], (int) $pattern['metric_id']);
            $approvedPatterns[$key][] = $pattern;
        }
        $openEpisodes = [];
        foreach ($this->analysis->openLevelEpisodes() as $episode) {
            $key = $this->metricKey((int) $episode['server_id'], (int) $episode['metric_id']);
            $openEpisodes[$key] = $episode;
        }

        $recent = [];
        foreach ($this->analysis->recentLevelBuckets($now) as $row) {
            $key = $this->metricKey((int) $row['server_id'], (int) $row['metric_id']);
            $recent[$key][] = [
                'time' => (string) $row['time'],
                'value' => (float) $row['value'],
            ];
        }

        foreach ($this->analysis->levelBaselines($now) as $baseline) {
            $serverId = (int) $baseline['server_id'];
            $metricId = (int) $baseline['metric_id'];
            $metricName = (string) $baseline['metric_name'];
            $key = $this->metricKey($serverId, $metricId);
            $recentPoints = $recent[$key] ?? [];
            if (count($recentPoints) >= 6) {
                $evaluatedMetrics[] = $key;
            }

            $evaluation = $this->levelAnalyzer->evaluate(
                $metricName,
                (float) $baseline['warning_threshold'],
                [
                    'p10' => (float) $baseline['p10'],
                    'median' => (float) $baseline['median'],
                    'p90' => (float) $baseline['p90'],
                    'points' => (int) $baseline['points'],
                    'weeks' => (int) $baseline['weeks'],
                    'first_at' => (string) $baseline['first_at'],
                    'last_at' => (string) $baseline['last_at'],
                    'context' => (string) $baseline['context'],
                    'timezone' => (string) $baseline['timezone'],
                    'local_weekday' => (int) $baseline['local_weekday'],
                    'local_hour' => (int) $baseline['local_hour'],
                ],
                $recentPoints,
                $now,
                $approvedPatterns[$key] ?? [],
                $openEpisodes[$key] ?? null
            );

            $state = (string) $evaluation['state'];
            if ($state === 'triggered') {
                $candidate = $evaluation['candidate'];
                if (!is_array($candidate)) {
                    continue;
                }
                $result = $this->observations->recordCandidate(
                    $serverId,
                    $metricId,
                    $candidate,
                    $now
                );
                $seen[] = (int) $result['id'];
                $openEpisodes[$key] = [
                    'id' => (int) $result['id'],
                    'server_id' => $serverId,
                    'metric_id' => $metricId,
                    'detector' => 'level_shift_v2',
                    'status' => (string) $result['status'],
                    'details' => $candidate['details'] ?? [],
                ];
                $resolvedEpisodes += (int) $result['resolved_siblings'];
                $detected++;
                if ($result['should_notify']) {
                    $notified += $this->notify(
                        $serverId,
                        (string) $baseline['server_name'],
                        $metricName,
                        $candidate,
                        $result,
                        $now
                    );
                }
                continue;
            }

            if (in_array($state, ['elevated', 'incident_owned'], true)) {
                $episodeId = $this->observations->touchAnomalyEpisode(
                    $serverId,
                    $metricId,
                    'level_shift_v2',
                    $evaluation['evidence'],
                    $now
                );
                if ($episodeId !== null) {
                    $seen[] = $episodeId;
                }
                continue;
            }

            if ($state === 'clear') {
                $resolvedEpisodes += $this->observations->resolveAnomalyEpisode(
                    $serverId,
                    $metricId,
                    'level_shift_v2',
                    $now
                );
            }
        }

        $diskGroups = [];
        foreach ($this->analysis->diskHistory($now) as $row) {
            $key = $this->metricKey((int) $row['server_id'], (int) $row['metric_id']);
            if (!isset($diskGroups[$key])) {
                $diskGroups[$key] = [
                    'server_id' => (int) $row['server_id'],
                    'server_name' => (string) $row['server_name'],
                    'metric_id' => (int) $row['metric_id'],
                    'metric_name' => (string) $row['metric_name'],
                    'warning_threshold' => (float) $row['warning_threshold'],
                    'current_value' => (float) $row['current_value'],
                    'total_gb' => $row['total_gb'] === null ? null : (float) $row['total_gb'],
                    'points' => [],
                ];
            }
            $diskGroups[$key]['points'][] = [
                'time' => (string) $row['time'],
                'value' => (float) $row['value'],
            ];
        }
        foreach ($diskGroups as $group) {
            $evaluatedMetrics[] = $this->metricKey((int) $group['server_id'], (int) $group['metric_id']);
        }
        $diskResolution = $this->diskAliases->coalesce(array_values($diskGroups));
        $resolvedAliases = $this->observations->resolveDiskAliases(
            $diskResolution['alias_metric_keys'],
            $now
        );
        foreach ($diskResolution['groups'] as $group) {
            $serverId = (int) $group['server_id'];
            $metricId = (int) $group['metric_id'];
            $candidate = $this->analyzer->detectDiskGrowth(
                (string) $group['metric_name'],
                (float) $group['warning_threshold'],
                $group['points'],
                (float) $group['current_value'],
                $now
            );
            if ($candidate === null) {
                continue;
            }
            if (($group['aliases'] ?? []) !== []) {
                $candidate['details']['aliases'] = array_values($group['aliases']);
            }
            $result = $this->observations->recordCandidate(
                $serverId,
                $metricId,
                $candidate,
                $now
            );
            $seen[] = (int) $result['id'];
            $resolvedEpisodes += (int) $result['resolved_siblings'];
            $detected++;
            if ($result['should_notify']) {
                $notified += $this->notify(
                    $serverId,
                    (string) $group['server_name'],
                    (string) $group['metric_name'],
                    $candidate,
                    $result,
                    $now
                );
            }
        }

        return [
            'detected' => $detected,
            'notified' => $notified,
            'resolved' => $resolvedEpisodes + $resolvedAliases + $this->observations->resolveMissing(
                $seen,
                $evaluatedMetrics,
                $now
            ),
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array{id:int,status:string,notification_cycle:int,should_notify:bool,resolved_siblings:int} $result
     */
    private function notify(
        int $serverId,
        string $serverName,
        string $metricName,
        array $candidate,
        array $result,
        DateTimeImmutable $now
    ): int {
        $kind = (string) $candidate['kind'];
        $payload = [
            'type' => 'observation',
            'kind' => $kind,
            'event' => 'detected',
            'server_id' => $serverId,
            'server_name' => $serverName,
            'observation_id' => (int) $result['id'],
            'metric' => $metricName,
            'current_value' => $candidate['current_value'] ?? null,
            'baseline_value' => $candidate['baseline_value'] ?? null,
            'confidence' => $candidate['confidence'] ?? null,
            'forecast_at' => $candidate['forecast_at'] ?? null,
            'details' => $candidate['details'] ?? [],
            'severity' => 'warning',
            'event_time' => $now->format(DATE_ATOM),
        ];
        $inserted = $this->outbox->enqueueObservationConfigured(
            $serverId,
            (int) $result['id'],
            'observation_' . $kind,
            $payload,
            sprintf(
                'observation:%d:cycle:%d',
                (int) $result['id'],
                (int) $result['notification_cycle']
            )
        );

        // Matching incident behavior: one detection attempt per observation cycle.
        // Maintenance or missing recipients suppress delivery rather than creating
        // a future notification storm when configuration changes.
        $this->observations->markNotified(
            (int) $result['id'],
            (int) $result['notification_cycle'],
            $now
        );
        return $inserted > 0 ? 1 : 0;
    }

    private function metricKey(int $serverId, int $metricId): string
    {
        return $serverId . ':' . $metricId;
    }
}
