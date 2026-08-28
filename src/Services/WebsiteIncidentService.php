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
        $this->transaction(function () use ($result): void {
            $state = $this->lockEndpointState($result->websiteId, $result->endpointId);
            if ($state === null || !$this->websiteIsActive($result->websiteId)) {
                return;
            }
            $decision = $this->evaluator->evaluate($state, $result);
            $this->updateEndpointState($result, $decision->nextState);
            $this->metrics->record($result);

            foreach ($decision->dimensions as $dimension) {
                $alertId = $dimension['open']
                    ? $this->openAlert($result, $dimension)
                    : null;
                if ($dimension['close']) {
                    $alertId = $this->closeAlert(
                        $result->websiteId,
                        $result->endpointId,
                        $dimension['kind'],
                        $dimension['effective_at'],
                    );
                }
                if (!$dimension['open'] && !$dimension['close']
                    && $dimension['dimension'] === 'transport'
                    && $dimension['diagnostic'] !== null
                ) {
                    $this->updateAlertDiagnostic(
                        $result->websiteId,
                        $result->endpointId,
                        $dimension['kind'],
                        $dimension['diagnostic'],
                    );
                }
                if ($dimension['dimension'] === 'transport' && ($dimension['open'] || $dimension['close'])) {
                    $this->availability->record(
                        $result->websiteId,
                        $result->endpointId,
                        $dimension['open'] ? 'unavailable' : 'available',
                        $dimension['effective_at'] ?? $result->checkedAt,
                        $alertId,
                    );
                }
            }
            $this->refreshWebsiteState($result->websiteId, $result->checkedAt);
        });
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

    private function refreshWebsiteState(int $websiteId, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "SELECT
                bool_or(state.transport_state = 'problem') FILTER (WHERE endpoints.is_primary) AS primary_down,
                bool_or(state.transport_state = 'problem') AS any_down,
                bool_or(state.assertion_state = 'problem') AS assertion_problem,
                bool_or(state.performance_state = 'problem') AS performance_problem,
                count(*) FILTER (WHERE state.transport_state = 'problem'
                    OR state.assertion_state = 'problem' OR state.performance_state = 'problem') AS problems,
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

        return $result->error?->value;
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
