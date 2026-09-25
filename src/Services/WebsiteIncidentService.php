<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Websites\DomainRegistrationResult;
use App\Domain\Websites\TlsInspectionResult;
use App\Domain\Websites\WebsiteCheckResult;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\WebsiteAvailabilityRepository;
use App\Repositories\WebsiteMetricsRepository;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDO;
use Throwable;

final class WebsiteIncidentService
{
    private readonly WebsiteAvailabilityRepository $availability;
    private readonly WebsiteUrlSanitizer $urlSanitizer;

    public function __construct(
        private readonly PDO $pdo,
        private readonly WebsiteStateEvaluator $evaluator,
        private readonly WebsiteMetricsRepository $metrics,
        private readonly NotificationOutboxRepository $notifications,
        ?WebsiteAvailabilityRepository $availability = null,
    ) {
        $this->availability = $availability ?? new WebsiteAvailabilityRepository($pdo);
        $this->urlSanitizer = new WebsiteUrlSanitizer();
    }

    public function recordHttp(WebsiteCheckResult $result): void
    {
        $this->recordHttpResult($result, true);
    }

    public function recordRemoteHttp(WebsiteCheckResult $result): void
    {
        $this->recordHttpResult($result, false);
    }

    private function recordHttpResult(
        WebsiteCheckResult $result,
        bool $centralSample,
    ): void {
        $this->transaction(function () use ($result, $centralSample): void {
            $state = $this->lockEndpointState($result->websiteId, $result->endpointId);
            if ($state === null || !$this->websiteIsActive($result->websiteId)) {
                return;
            }

            if ($centralSample) {
                $this->metrics->record($result);
            } else {
                $this->recordRemoteSample($result);
                if (
                    $state['last_sample_at'] !== null
                    && $result->checkedAt < new DateTimeImmutable((string) $state['last_sample_at'])
                ) {
                    // Backfilled durable-queue observations belong in history,
                    // but must not rewind the live incident state.
                    return;
                }
            }

            $effective = $this->withQuorumTransport($result);
            $decision = $this->evaluator->evaluate($state, $effective);
            $this->updateEndpointState($effective, $decision->nextState);

            foreach ($decision->dimensions as $dimension) {
                $alertId = $dimension['open']
                    ? $this->openAlert($effective, $dimension)
                    : null;
                if ($dimension['close']) {
                    $alertId = $this->closeAlert(
                        $effective->websiteId,
                        $effective->endpointId,
                        $dimension['kind'],
                        $dimension['effective_at'],
                    );
                }
                if (!$dimension['open'] && !$dimension['close']
                    && $dimension['dimension'] === 'transport'
                    && $dimension['diagnostic'] !== null
                ) {
                    $this->updateAlertDiagnostic(
                        $effective->websiteId,
                        $effective->endpointId,
                        $dimension['kind'],
                        $dimension['diagnostic'],
                    );
                }
                if ($dimension['dimension'] === 'transport' && ($dimension['open'] || $dimension['close'])) {
                    $this->availability->record(
                        $effective->websiteId,
                        $effective->endpointId,
                        $dimension['open'] ? 'unavailable' : 'available',
                        $dimension['effective_at'] ?? $effective->checkedAt,
                        $alertId,
                    );
                }
            }
            $this->refreshWebsiteState($effective->websiteId, $effective->checkedAt);
            $this->notifications->flushMaintenanceDeferralsForWebsite(
                $effective->websiteId
            );
        });
    }

    private function recordRemoteSample(WebsiteCheckResult $result): void
    {
        if ($result->probeId === null || filter_var(
            $result->probeId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        ) === false) {
            throw new InvalidArgumentException('Remote website probe identity is invalid.');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO website_probe_samples (
                sample_time,
                website_id,
                endpoint_id,
                server_id,
                sample_id,
                transport_available,
                status_code,
                total_ms,
                error_kind,
                safe_message
            ) VALUES (
                :sample_time,
                :website_id,
                :endpoint_id,
                :server_id,
                :sample_id,
                :transport_available,
                :status_code,
                :total_ms,
                :error_kind,
                :safe_message
            )
            SQL
        );
        $statement->execute([
            'sample_time' => $result->checkedAt->format(DateTimeInterface::ATOM),
            'website_id' => $result->websiteId,
            'endpoint_id' => $result->endpointId,
            'server_id' => (int) $result->probeId,
            'sample_id' => $result->sampleId,
            'transport_available' => $result->transportAvailable ? 1 : 0,
            'status_code' => $result->statusCode,
            'total_ms' => $result->timings['total_ms'],
            'error_kind' => $result->error?->value,
            'safe_message' => $result->safeMessage === null
                ? null
                : mb_substr($result->safeMessage, 0, 500),
        ]);
    }

    private function withQuorumTransport(WebsiteCheckResult $result): WebsiteCheckResult
    {
        $website = $this->pdo->prepare(
            'SELECT
                websites.probe_quorum,
                COALESCE(endpoints.interval_seconds, websites.default_interval_seconds) AS interval_seconds
             FROM websites
             JOIN website_endpoints AS endpoints
               ON endpoints.website_id = websites.id
              AND endpoints.id = :endpoint_id
             WHERE websites.id = :website_id'
        );
        $website->execute([
            'website_id' => $result->websiteId,
            'endpoint_id' => $result->endpointId,
        ]);
        $settings = $website->fetch();
        if (!is_array($settings)) {
            return $result;
        }

        $quorum = max(1, (int) $settings['probe_quorum']);
        $agents = $this->pdo->prepare(
            'SELECT server_id
             FROM website_probe_agents
             WHERE website_id = :website_id
             ORDER BY server_id'
        );
        $agents->execute(['website_id' => $result->websiteId]);
        $agentIds = array_map(
            static fn (array $row): string => (string) $row['server_id'],
            $agents->fetchAll()
        );

        $selected = 1 + count($agentIds);

        $freshnessSeconds = max(120, (int) $settings['interval_seconds'] * 3);
        $freshSince = $result->checkedAt
            ->modify('-' . $freshnessSeconds . ' seconds')
            ->format('Y-m-d H:i:sP');
        $failures = 0;
        $observed = 0;

        $centralSample = $this->pdo->prepare(
            'SELECT transport_available
             FROM website_check_samples
             WHERE endpoint_id = :endpoint_id
               AND sample_time >= :fresh_since
             ORDER BY sample_time DESC, sample_id DESC
             LIMIT 1'
        );
        $centralSample->execute([
            'endpoint_id' => $result->endpointId,
            'fresh_since' => $freshSince,
        ]);
        $row = $centralSample->fetch();
        if (is_array($row)) {
            $observed++;
            if (!$this->boolValue($row['transport_available'])) {
                $failures++;
            }
        }

        if ($agentIds !== []) {
            $placeholders = [];
            $params = [
                'endpoint_id' => $result->endpointId,
                'fresh_since' => $freshSince,
            ];
            foreach ($agentIds as $index => $agentId) {
                $key = 'probe_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $agentId;
            }
            $remote = $this->pdo->prepare(
                'SELECT DISTINCT ON (server_id)
                    server_id, transport_available
                 FROM website_probe_samples
                 WHERE endpoint_id = :endpoint_id
                   AND sample_time >= :fresh_since
                   AND server_id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY server_id, sample_time DESC, sample_id DESC'
            );
            $remote->execute($params);
            foreach ($remote->fetchAll() as $row) {
                $observed++;
                if (!$this->boolValue($row['transport_available'])) {
                    $failures++;
                }
            }
        }

        $available = $failures < $quorum;
        $diagnostic = sprintf(
            'Probe quorum: %d/%d failed, quorum %d%s.',
            $failures,
            $selected,
            $quorum,
            $observed < $selected ? sprintf(', %d reporting', $observed) : ''
        );

        // Assertions and performance remain authoritative only on the central
        // checker. Agent probes intentionally contribute transport only.
        $central = $result->probeKind === 'app';

        return new WebsiteCheckResult(
            websiteId: $result->websiteId,
            endpointId: $result->endpointId,
            sampleId: $result->sampleId,
            checkedAt: $result->checkedAt,
            transportAvailable: $available,
            assertionsPassed: $central ? $result->assertionsPassed : true,
            statusCode: $result->statusCode,
            configuredUrl: $result->configuredUrl,
            finalUrl: $result->finalUrl,
            redirectChain: $result->redirectChain,
            timings: $result->timings,
            error: $central ? $result->error : null,
            assertionResults: $central ? $result->assertionResults : [],
            manual: $result->manual,
            probeKind: $result->probeKind,
            probeId: $result->probeId,
            safeMessage: $diagnostic,
        );
    }

    public function recordTls(TlsInspectionResult $result): void
    {
        if ($result->targetId === null) {
            throw new InvalidArgumentException('TLS target is required.');
        }
        $this->transaction(function () use ($result): void {
            $statement = $this->pdo->prepare(
                'SELECT targets.website_id, websites.tls_warning_days, websites.tls_critical_days
                 FROM website_tls_targets AS targets JOIN websites ON websites.id = targets.website_id
                 WHERE targets.id = :target_id FOR UPDATE'
            );
            $statement->execute(['target_id' => $result->targetId]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                throw new InvalidArgumentException('TLS target does not exist.');
            }
            $severity = $result->valid ? null : 'critical';
            if ($result->valid && $result->notAfter !== null) {
                $days = (int) floor(($result->notAfter->getTimestamp() - $result->checkedAt->getTimestamp()) / 86400);
                $severity = $days <= (int) $row['tls_critical_days']
                    ? 'critical'
                    : ($days <= (int) $row['tls_warning_days'] ? 'warning' : null);
            }
            if ($severity === null) {
                $this->closeAlert((int) $row['website_id'], $result->endpointId, 'website_tls', $result->checkedAt);
                $this->notifications->flushMaintenanceDeferralsForWebsite(
                    (int) $row['website_id']
                );
                return;
            }
            $this->openSourceAlert(
                (int) $row['website_id'],
                $result->endpointId,
                'website_tls',
                $severity,
                $result->hostname,
                $result->checkedAt,
                $result->errorKind,
                ['hostname' => $result->hostname, 'event_time' => $result->checkedAt->format(DATE_ATOM)],
            );
            $this->notifications->flushMaintenanceDeferralsForWebsite(
                (int) $row['website_id']
            );
        });
    }

    public function recordDomain(int $websiteId, DomainRegistrationResult $result): void
    {
        if ($websiteId <= 0) {
            throw new InvalidArgumentException('Website does not exist.');
        }
        $this->transaction(function () use ($websiteId, $result): void {
            $statement = $this->pdo->prepare(
                'SELECT domain_warning_days, domain_critical_days
                 FROM websites WHERE id = :website_id FOR UPDATE'
            );
            $statement->execute(['website_id' => $websiteId]);
            $website = $statement->fetch();
            if (!is_array($website)) {
                throw new InvalidArgumentException('Website does not exist.');
            }
            $severity = null;
            if ($result->status === 'supported' && $result->expiresAt !== null) {
                $days = (int) floor(($result->expiresAt->getTimestamp() - $result->checkedAt->getTimestamp()) / 86400);
                $severity = $days <= (int) $website['domain_critical_days']
                    ? 'critical'
                    : ($days <= (int) $website['domain_warning_days'] ? 'warning' : null);
            }
            if ($severity === null) {
                $this->closeAlert($websiteId, null, 'website_domain', $result->checkedAt);
                $this->notifications->flushMaintenanceDeferralsForWebsite($websiteId);
                return;
            }
            $this->openSourceAlert(
                $websiteId,
                null,
                'website_domain',
                $severity,
                $result->domain,
                $result->checkedAt,
                $result->expiresAt?->format(DATE_ATOM),
                ['domain' => $result->domain, 'event_time' => $result->checkedAt->format(DATE_ATOM)],
            );
            $this->notifications->flushMaintenanceDeferralsForWebsite($websiteId);
        });
    }

    public function pause(int $websiteId, DateTimeImmutable $at): void
    {
        $this->transaction(function () use ($websiteId, $at): void {
            $statement = $this->pdo->prepare(
                'UPDATE alerts
                 SET resolved = TRUE, resolved_at = :resolved_at,
                     resolution_reason = \'monitoring_paused\'
                 WHERE website_id = :website_id AND resolved = FALSE'
            );
            $statement->execute([
                'website_id' => $websiteId,
                'resolved_at' => $at->format(DateTimeInterface::ATOM),
            ]);
            $this->pdo->prepare(
                "UPDATE website_endpoint_state
                 SET transport_state = 'paused', assertion_state = 'paused', performance_state = 'paused'
                 WHERE website_id = :website_id"
            )->execute(['website_id' => $websiteId]);
            $this->pdo->prepare(
                "UPDATE website_state SET status = 'paused', active_problem_count = 0, updated_at = :at
                 WHERE website_id = :website_id"
            )->execute(['website_id' => $websiteId, 'at' => $at->format(DateTimeInterface::ATOM)]);
        });
    }

    /** @return array<string, mixed>|null */
    private function lockEndpointState(int $websiteId, int $endpointId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM website_endpoint_state
             WHERE website_id = :website_id AND endpoint_id = :endpoint_id FOR UPDATE'
        );
        $statement->execute(['website_id' => $websiteId, 'endpoint_id' => $endpointId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function websiteIsActive(int $websiteId): bool
    {
        $statement = $this->pdo->prepare('SELECT is_active FROM websites WHERE id = :website_id');
        $statement->execute(['website_id' => $websiteId]);
        $value = $statement->fetchColumn();

        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    /** @param array<string, mixed> $state */
    private function updateEndpointState(WebsiteCheckResult $result, array $state): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE website_endpoint_state SET
                transport_state = :transport_state,
                assertion_state = :assertion_state,
                performance_state = :performance_state,
                transport_failures = :transport_failures,
                transport_successes = :transport_successes,
                assertion_failures = :assertion_failures,
                assertion_successes = :assertion_successes,
                performance_failures = :performance_failures,
                performance_successes = :performance_successes,
                transport_series_started_at = :transport_series_started_at,
                assertion_series_started_at = :assertion_series_started_at,
                performance_series_started_at = :performance_series_started_at,
                last_sample_at = :last_sample_at,
                last_status_code = :last_status_code,
                last_final_url = :last_final_url,
                last_redirect_count = :last_redirect_count,
                last_ttfb_ms = :last_ttfb_ms,
                last_total_ms = :last_total_ms,
                last_error_kind = :last_error_kind,
                last_safe_message = :last_safe_message
             WHERE website_id = :website_id AND endpoint_id = :endpoint_id'
        );
        $statement->execute([
            'transport_state' => $state['transport_state'],
            'assertion_state' => $state['assertion_state'] ?? 'no_data',
            'performance_state' => $state['performance_state'] ?? 'no_data',
            'transport_failures' => $state['transport_failures'] ?? 0,
            'transport_successes' => $state['transport_successes'] ?? 0,
            'assertion_failures' => $state['assertion_failures'] ?? 0,
            'assertion_successes' => $state['assertion_successes'] ?? 0,
            'performance_failures' => $state['performance_failures'] ?? 0,
            'performance_successes' => $state['performance_successes'] ?? 0,
            'transport_series_started_at' => $state['transport_series_started_at'],
            'assertion_series_started_at' => $state['assertion_series_started_at'],
            'performance_series_started_at' => $state['performance_series_started_at'],
            'last_sample_at' => $result->checkedAt->format(DateTimeInterface::ATOM),
            'last_status_code' => $result->statusCode,
            'last_final_url' => $result->finalUrl,
            'last_redirect_count' => count($result->redirectChain),
            'last_ttfb_ms' => $result->timings['ttfb_ms'],
            'last_total_ms' => $result->timings['total_ms'],
            'last_error_kind' => $result->error?->value,
            'last_safe_message' => $this->safeMessage($result),
            'website_id' => $result->websiteId,
            'endpoint_id' => $result->endpointId,
        ]);
    }

    /** @param array{dimension:string,kind:string,severity:string,open:bool,close:bool,effective_at:?DateTimeImmutable,diagnostic:?string} $dimension */
    private function openAlert(WebsiteCheckResult $result, array $dimension): int
    {
        $subject = $this->endpointName($result->endpointId);
        return $this->openSourceAlert(
            $result->websiteId,
            $result->endpointId,
            $dimension['kind'],
            $dimension['severity'],
            $subject,
            $dimension['effective_at'] ?? $result->checkedAt,
            $dimension['diagnostic'],
            [
                'type' => $dimension['kind'],
                'event' => 'triggered',
                'severity' => $dimension['severity'],
                'endpoint_id' => $result->endpointId,
                'endpoint_name' => $subject,
                'event_time' => ($dimension['effective_at'] ?? $result->checkedAt)->format(DATE_ATOM),
                'safe_url' => $this->urlSanitizer->forDisplay($result->configuredUrl),
            ],
        );
    }

    /** @param array<string, scalar|null> $payload */
    private function openSourceAlert(
        int $websiteId,
        ?int $endpointId,
        string $kind,
        string $severity,
        string $subject,
        DateTimeImmutable $effectiveAt,
        ?string $diagnostic,
        array $payload,
    ): int {
        $details = json_encode(['diagnostic' => $diagnostic], JSON_THROW_ON_ERROR);
        $insert = $this->pdo->prepare(
            'INSERT INTO alerts (website_id, endpoint_id, kind, subject, severity, details, created_at)
             VALUES (:website_id, :endpoint_id, :kind, :subject, :severity, CAST(:details AS jsonb), :created_at)
             ON CONFLICT DO NOTHING RETURNING id'
        );
        $insert->execute([
            'website_id' => $websiteId,
            'endpoint_id' => $endpointId,
            'kind' => $kind,
            'subject' => $subject,
            'severity' => $severity,
            'details' => $details,
            'created_at' => $effectiveAt->format(DateTimeInterface::ATOM),
        ]);
        $alertId = (int) ($insert->fetchColumn() ?: $this->activeAlertId($websiteId, $endpointId, $kind));
        $payload['website_id'] = $websiteId;
        $payload['website_name'] = $this->websiteName($websiteId);
        $this->notifications->enqueueWebsiteConfigured(
            $websiteId,
            $alertId,
            $kind . '_triggered',
            $payload,
            'website:' . $websiteId . ':alert:' . $alertId . ':triggered',
        );

        return $alertId;
    }

    private function closeAlert(int $websiteId, ?int $endpointId, string $kind, ?DateTimeImmutable $at): ?int
    {
        $alertId = $this->activeAlertId($websiteId, $endpointId, $kind);
        if ($alertId === 0) {
            return null;
        }
        $statement = $this->pdo->prepare(
            "UPDATE alerts SET resolved = TRUE, resolved_at = :resolved_at,
                    resolution_reason = 'confirmed_recovery'
             WHERE id = :id AND resolved = FALSE"
        );
        $statement->execute([
            'id' => $alertId,
            'resolved_at' => ($at ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]);
        $payload = [
            'type' => $kind,
            'event' => 'recovered',
            'severity' => 'critical',
            'website_id' => $websiteId,
            'website_name' => $this->websiteName($websiteId),
            'event_time' => ($at ?? new DateTimeImmutable())->format(DATE_ATOM),
        ];
        if ($endpointId !== null) {
            $payload['endpoint_id'] = $endpointId;
            $payload['endpoint_name'] = $this->endpointName($endpointId);
        }
        $this->notifications->enqueueWebsiteConfigured(
            $websiteId,
            $alertId,
            $kind . '_recovered',
            $payload,
            'website:' . $websiteId . ':alert:' . $alertId . ':recovered',
        );

        return $alertId;
    }

    private function activeAlertId(int $websiteId, ?int $endpointId, string $kind): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM alerts WHERE website_id = :website_id
             AND endpoint_id IS NOT DISTINCT FROM CAST(:endpoint_id AS bigint)
             AND kind = :kind AND resolved = FALSE ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['website_id' => $websiteId, 'endpoint_id' => $endpointId, 'kind' => $kind]);
        $id = $statement->fetchColumn();

        return $id === false ? 0 : (int) $id;
    }

    private function updateAlertDiagnostic(int $websiteId, int $endpointId, string $kind, string $diagnostic): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE alerts SET details = jsonb_build_object('diagnostic', CAST(:diagnostic AS text))
             WHERE website_id = :website_id AND endpoint_id = :endpoint_id
               AND kind = :kind AND resolved = FALSE"
        );
        $statement->execute([
            'diagnostic' => $diagnostic,
            'website_id' => $websiteId,
            'endpoint_id' => $endpointId,
            'kind' => $kind,
        ]);
    }

    private function endpointName(int $endpointId): string
    {
        $statement = $this->pdo->prepare('SELECT name FROM website_endpoints WHERE id = :endpoint_id');
        $statement->execute(['endpoint_id' => $endpointId]);

        return (string) $statement->fetchColumn();
    }

    private function websiteName(int $websiteId): string
    {
        $statement = $this->pdo->prepare('SELECT name FROM websites WHERE id = :website_id');
        $statement->execute(['website_id' => $websiteId]);

        $name = trim((string) $statement->fetchColumn());

        return $name === '' ? 'unknown' : $name;
    }

    private function refreshWebsiteState(int $websiteId, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "SELECT
                bool_or(state.transport_state IN ('problem', 'recovering')) FILTER (WHERE endpoints.is_primary) AS primary_down,
                bool_or(state.transport_state IN ('problem', 'recovering')) AS any_down,
                bool_or(state.assertion_state IN ('problem', 'recovering')) AS assertion_problem,
                bool_or(state.performance_state IN ('problem', 'recovering')) AS performance_problem,
                count(*) FILTER (WHERE state.transport_state IN ('problem', 'recovering')
                    OR state.assertion_state IN ('problem', 'recovering')
                    OR state.performance_state IN ('problem', 'recovering')) AS problems,
                bool_or(state.last_sample_at IS NOT NULL) AS has_sample
             FROM website_endpoint_state state
             JOIN website_endpoints endpoints ON endpoints.id = state.endpoint_id
             WHERE state.website_id = :website_id"
        );
        $statement->execute(['website_id' => $websiteId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return;
        }
        $status = $this->boolValue($row['primary_down'])
            ? 'unavailable'
            : ($this->boolValue($row['performance_problem']) ? 'slow'
                : ($this->boolValue($row['assertion_problem']) || $this->boolValue($row['any_down']) ? 'problem'
                    : ($this->boolValue($row['has_sample']) ? 'healthy' : 'no_data')));
        $update = $this->pdo->prepare(
            'UPDATE website_state SET status = :status, active_problem_count = :count,
                updated_at = :updated_at WHERE website_id = :website_id'
        );
        $update->execute([
            'status' => $status,
            'count' => (int) $row['problems'],
            'updated_at' => $at->format(DateTimeInterface::ATOM),
            'website_id' => $websiteId,
        ]);
    }

    private function safeMessage(WebsiteCheckResult $result): ?string
    {
        foreach ($result->assertionResults as $assertion) {
            if (!$assertion['passed']) {
                return $assertion['safe_message'];
            }
        }

        return $result->safeMessage ?? $result->error?->value;
    }

    private function boolValue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function transaction(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $value = $callback();
            if ($owns) {
                $this->pdo->commit();
            }

            return $value;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
