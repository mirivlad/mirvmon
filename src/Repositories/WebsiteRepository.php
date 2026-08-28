<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Websites\WebsiteEndpointDefinition;
use App\Security\SecretCipher;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class WebsiteRepository
{
    private readonly AppSettingsRepository $settings;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretCipher $cipher,
        ?AppSettingsRepository $settings = null,
    ) {
        $this->settings = $settings ?? new AppSettingsRepository($pdo);
    }

    /**
     * @param array<string, mixed> $site
     * @param list<WebsiteEndpointDefinition> $endpoints
     */
    public function create(array $site, array $endpoints): int
    {
        $site = $this->normalizeSite($site);
        $endpoints = $this->primaryEndpoints($endpoints, false);

        return $this->transaction(function () use ($site, $endpoints): int {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO websites (
                    group_id,
                    name,
                    description,
                    registration_domain,
                    domain_check_enabled,
                    default_interval_seconds,
                    tls_warning_days,
                    tls_critical_days,
                    domain_warning_days,
                    domain_critical_days,
                    notification_telegram_chat_id,
                    notification_emails,
                    domain_next_check_at
                ) VALUES (
                    :group_id,
                    :name,
                    :description,
                    :registration_domain,
                    :domain_check_enabled,
                    :default_interval_seconds,
                    :tls_warning_days,
                    :tls_critical_days,
                    :domain_warning_days,
                    :domain_critical_days,
                    :notification_telegram_chat_id,
                    CAST(:notification_emails AS jsonb),
                    CASE WHEN :domain_check_enabled THEN CURRENT_TIMESTAMP ELSE NULL END
                )
                RETURNING id
                SQL
            );
            $statement->execute($site);
            $websiteId = (int) $statement->fetchColumn();

            $primaryEndpointId = null;
            foreach ($endpoints as $endpoint) {
                $endpointId = $this->insertEndpoint(
                    $websiteId,
                    $endpoint['definition'],
                    $endpoint['primary']
                );
                if ($endpoint['primary']) {
                    $primaryEndpointId = $endpointId;
                }
            }
            if ($primaryEndpointId === null) {
                throw new RuntimeException('Website primary endpoint was not created.');
            }

            $state = $this->pdo->prepare(
                'INSERT INTO website_state (website_id, primary_endpoint_id)
                 VALUES (:website_id, :primary_endpoint_id)'
            );
            $state->execute([
                'website_id' => $websiteId,
                'primary_endpoint_id' => $primaryEndpointId,
            ]);
            $this->pdo->prepare(
                'INSERT INTO website_domain_state (website_id) VALUES (:website_id)'
            )->execute(['website_id' => $websiteId]);

            return $websiteId;
        });
    }

    /**
     * @param array<string, mixed> $site
     * @param list<WebsiteEndpointDefinition> $endpoints
     */
    public function update(int $websiteId, array $site, array $endpoints): void
    {
        if ($websiteId <= 0) {
            throw new InvalidArgumentException('Website does not exist.');
        }
        $endpoints = $this->primaryEndpoints($endpoints, true);

        $this->transaction(function () use ($websiteId, $site, $endpoints): void {
            $lock = $this->pdo->prepare('SELECT * FROM websites WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $websiteId]);
            $currentSite = $lock->fetch();
            if (!is_array($currentSite)) {
                throw new InvalidArgumentException('Website does not exist.');
            }
            $site = $this->normalizeSite(array_replace($currentSite, $site));

            $currentStatement = $this->pdo->prepare(
                'SELECT id, auth_type, auth_encrypted, headers_encrypted
                 FROM website_endpoints
                 WHERE website_id = :website_id
                 ORDER BY id
                 FOR UPDATE'
            );
            $currentStatement->execute(['website_id' => $websiteId]);
            $current = [];
            foreach ($currentStatement->fetchAll() as $row) {
                $current[(int) $row['id']] = $row;
            }

            $submittedIds = [];
            foreach ($endpoints as $endpoint) {
                $id = $endpoint['definition']->id;
                if ($id === null) {
                    continue;
                }
                if (!isset($current[$id]) || isset($submittedIds[$id])) {
                    throw new InvalidArgumentException('Website endpoint does not belong to this website.');
                }
                $submittedIds[$id] = true;
            }

            $this->pdo->prepare(
                'UPDATE website_endpoints SET is_primary = FALSE WHERE website_id = :website_id'
            )->execute(['website_id' => $websiteId]);

            $keptIds = [];
            $primaryEndpointId = null;
            foreach ($endpoints as $endpoint) {
                $definition = $endpoint['definition'];
                if ($definition->id === null) {
                    $endpointId = $this->insertEndpoint(
                        $websiteId,
                        $definition,
                        $endpoint['primary']
                    );
                } else {
                    $endpointId = $definition->id;
                    $this->updateEndpoint(
                        $websiteId,
                        $definition,
                        $endpoint['primary'],
                        $current[$endpointId]
                    );
                }
                $keptIds[$endpointId] = true;
                if ($endpoint['primary']) {
                    $primaryEndpointId = $endpointId;
                }
            }
            if ($primaryEndpointId === null) {
                throw new RuntimeException('Website primary endpoint was not selected.');
            }

            $this->saveSite($websiteId, $site);
            $this->pdo->prepare(
                'UPDATE website_state
                 SET primary_endpoint_id = :primary_endpoint_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE website_id = :website_id'
            )->execute([
                'website_id' => $websiteId,
                'primary_endpoint_id' => $primaryEndpointId,
            ]);

            $removedIds = array_diff(array_keys($current), array_keys($keptIds));
            if ($removedIds !== []) {
                $placeholders = [];
                $params = ['website_id' => $websiteId];
                foreach (array_values($removedIds) as $index => $id) {
                    $key = 'removed_' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $id;
                }
                $delete = $this->pdo->prepare(
                    'DELETE FROM website_endpoints
                     WHERE website_id = :website_id
                       AND id IN (' . implode(', ', $placeholders) . ')'
                );
                $delete->execute($params);
            }
        });
    }

    /** @param array<string, mixed> $settings */
    public function updateSettings(int $websiteId, array $settings): void
    {
        $this->transaction(function () use ($websiteId, $settings): void {
            $current = $this->find($websiteId);
            if ($current === null) {
                throw new InvalidArgumentException('Website does not exist.');
            }
            $this->saveSite($websiteId, $this->normalizeSite(array_replace($current, $settings)));
        });
    }

    public function delete(int $websiteId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM websites WHERE id = :id');
        $statement->execute(['id' => $websiteId]);
    }

    public function pause(int $websiteId, DateTimeImmutable $at): void
    {
        $this->transaction(function () use ($websiteId, $at): void {
            $statement = $this->pdo->prepare(
                'UPDATE websites
                 SET is_active = FALSE, paused_at = :paused_at
                 WHERE id = :website_id'
            );
            $statement->execute([
                'website_id' => $websiteId,
                'paused_at' => $at->format(DateTimeInterface::ATOM),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('Website does not exist.');
            }
            $this->pdo->prepare(
                "UPDATE website_endpoint_state
                 SET transport_state = 'paused', assertion_state = 'paused',
                     performance_state = 'paused'
                 WHERE website_id = :website_id"
            )->execute(['website_id' => $websiteId]);
            $this->pdo->prepare(
                "UPDATE website_state SET status = 'paused', updated_at = :paused_at
                 WHERE website_id = :website_id"
            )->execute([
                'website_id' => $websiteId,
                'paused_at' => $at->format(DateTimeInterface::ATOM),
            ]);
            $this->pdo->prepare(
                "DELETE FROM website_check_jobs
                 WHERE website_id = :website_id AND state = 'pending'"
            )->execute(['website_id' => $websiteId]);
        });
    }

    public function resume(int $websiteId, DateTimeImmutable $at): void
    {
        $this->transaction(function () use ($websiteId, $at): void {
            $statement = $this->pdo->prepare(
                'UPDATE websites
                 SET is_active = TRUE,
                     paused_at = NULL,
                     domain_next_check_at = CASE
                         WHEN domain_check_enabled THEN CAST(:resumed_at AS timestamptz)
                         ELSE NULL
                     END
                 WHERE id = :website_id'
            );
            $statement->execute([
                'website_id' => $websiteId,
                'resumed_at' => $at->format(DateTimeInterface::ATOM),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('Website does not exist.');
            }
            $this->pdo->prepare(
                'UPDATE website_endpoints
                 SET next_http_check_at = :resumed_at
                 WHERE website_id = :website_id'
            )->execute([
                'website_id' => $websiteId,
                'resumed_at' => $at->format(DateTimeInterface::ATOM),
            ]);
            $this->pdo->prepare(
                "UPDATE website_endpoint_state
                 SET transport_state = 'no_data', assertion_state = 'no_data',
                     performance_state = 'no_data', performance_severity = NULL,
                     transport_failures = 0, transport_successes = 0,
                     assertion_failures = 0, assertion_successes = 0,
                     performance_failures = 0, performance_successes = 0,
                     transport_series_started_at = NULL,
                     assertion_series_started_at = NULL,
                     performance_series_started_at = NULL
                 WHERE website_id = :website_id"
            )->execute(['website_id' => $websiteId]);
            $this->pdo->prepare(
                "UPDATE website_state
                 SET status = 'no_data', active_problem_count = 0,
                     possible_problem_text = NULL, updated_at = :resumed_at
                 WHERE website_id = :website_id"
            )->execute([
                'website_id' => $websiteId,
                'resumed_at' => $at->format(DateTimeInterface::ATOM),
            ]);
        });
    }

    /** @return array<string, mixed>|null */
    public function find(int $websiteId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                id, group_id, name, description, registration_domain,
                domain_check_enabled, default_interval_seconds,
                tls_warning_days, tls_critical_days,
                domain_warning_days, domain_critical_days,
                notification_telegram_chat_id, notification_emails,
                is_active, paused_at, domain_next_check_at, created_at, updated_at
            FROM websites
            WHERE id = :id
            SQL
        );
        $statement->execute(['id' => $websiteId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalizeSiteRow($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function detail(int $websiteId): ?array
    {
        $site = $this->find($websiteId);
        if ($site === null) {
            return null;
        }
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                endpoints.id,
                endpoints.website_id,
                endpoints.name,
                endpoints.url,
                endpoints.is_primary,
                endpoints.method,
                endpoints.interval_seconds,
                endpoints.timeout_seconds,
                endpoints.follow_redirects,
                endpoints.max_redirects,
                endpoints.status_check_enabled,
                endpoints.expected_status_ranges,
                endpoints.warning_total_ms,
                endpoints.critical_total_ms,
                endpoints.auth_type,
                endpoints.credential_redirect_hosts,
                endpoints.allow_self_signed,
                endpoints.tls_expiry_enabled,
                endpoints.next_http_check_at,
                endpoints.auth_encrypted IS NOT NULL AS has_auth,
                endpoints.headers_encrypted IS NOT NULL AS has_headers,
                state.transport_state,
                state.assertion_state,
                state.performance_state
            FROM website_endpoints AS endpoints
            LEFT JOIN website_endpoint_state AS state ON state.endpoint_id = endpoints.id
            WHERE endpoints.website_id = :website_id
            ORDER BY endpoints.is_primary DESC, endpoints.id
            SQL
        );
        $statement->execute(['website_id' => $websiteId]);
        $endpoints = [];
        foreach ($statement->fetchAll() as $row) {
            $row['id'] = (int) $row['id'];
            $row['website_id'] = (int) $row['website_id'];
            foreach ([
                'is_primary', 'follow_redirects', 'status_check_enabled',
                'allow_self_signed', 'tls_expiry_enabled', 'has_auth', 'has_headers',
            ] as $boolean) {
                $row[$boolean] = $this->databaseBool($row[$boolean]);
            }
            $row['content_checks'] = [];
            $endpoints[$row['id']] = $row;
        }
        if ($endpoints !== []) {
            $checks = $this->pdo->prepare(
                'SELECT checks.*
                 FROM website_content_checks AS checks
                 INNER JOIN website_endpoints AS endpoints ON endpoints.id = checks.endpoint_id
                 WHERE endpoints.website_id = :website_id
                 ORDER BY checks.endpoint_id, checks.sort_order, checks.id'
            );
            $checks->execute(['website_id' => $websiteId]);
            foreach ($checks->fetchAll() as $check) {
                $endpointId = (int) $check['endpoint_id'];
                if (isset($endpoints[$endpointId])) {
                    $endpoints[$endpointId]['content_checks'][] = $check;
                }
            }
        }
        $site['endpoints'] = array_values($endpoints);

        return $site;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array{id: ?int, name: string, websites: list<array<string, mixed>>}>
     */
    public function groupedList(array $filters): array
    {
        $where = [];
        $params = [];
        if (isset($filters['group_id']) && $filters['group_id'] !== '') {
            $groupId = filter_var($filters['group_id'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($groupId === false) {
                throw new InvalidArgumentException('Website group filter is invalid.');
            }
            $where[] = 'websites.group_id = :group_id';
            $params['group_id'] = $groupId;
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = (string) $filters['status'];
            if (!in_array($status, [
                'healthy', 'unavailable', 'problem', 'degraded', 'slow',
                'warning', 'critical', 'no_data', 'paused',
            ], true)) {
                throw new InvalidArgumentException('Website status filter is invalid.');
            }
            $where[] = 'state.status = :status';
            $params['status'] = $status;
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $where[] = '(websites.name ILIKE :search OR primary_endpoint.url ILIKE :search)';
            $params['search'] = '%' . $this->escapeLike(trim((string) $filters['search'])) . '%';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $statement = $this->pdo->prepare(
            <<<SQL
            SELECT
                websites.id,
                websites.group_id,
                websites.name,
                websites.description,
                websites.is_active,
                groups.name AS group_name,
                COALESCE(state.status, 'no_data') AS status,
                COALESCE(state.active_problem_count, 0) AS active_problem_count,
                state.possible_problem_text,
                primary_endpoint.id AS primary_endpoint_id,
                primary_endpoint.url AS primary_url
            FROM websites
            LEFT JOIN monitoring_groups AS groups ON groups.id = websites.group_id
            LEFT JOIN website_state AS state ON state.website_id = websites.id
            LEFT JOIN website_endpoints AS primary_endpoint
              ON primary_endpoint.website_id = websites.id
             AND primary_endpoint.is_primary = TRUE
            {$whereSql}
            ORDER BY groups.sort_order NULLS LAST, lower(groups.name) NULLS LAST,
                     lower(websites.name), websites.id
            SQL
        );
        $statement->execute($params);

        $groups = [];
        foreach ($statement->fetchAll() as $row) {
            $groupKey = $row['group_id'] === null ? 'none' : (string) $row['group_id'];
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'id' => $row['group_id'] === null ? null : (int) $row['group_id'],
                    'name' => $row['group_name'] === null ? '' : (string) $row['group_name'],
                    'websites' => [],
                ];
            }
            $groups[$groupKey]['websites'][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'is_active' => $this->databaseBool($row['is_active']),
                'status' => (string) $row['status'],
                'active_problem_count' => (int) $row['active_problem_count'],
                'possible_problem_text' => $row['possible_problem_text'],
                'primary_endpoint_id' => $row['primary_endpoint_id'] === null
                    ? null : (int) $row['primary_endpoint_id'],
                'primary_url' => $row['primary_url'] === null ? null : (string) $row['primary_url'],
            ];
        }

        return array_values($groups);
    }

    /** @return array<string, int> */
    public function dashboardSummary(): array
    {
        $row = $this->pdo->query(
            <<<'SQL'
            SELECT
                count(*) AS total,
                count(*) FILTER (WHERE COALESCE(state.status, 'no_data') = 'healthy') AS healthy,
                count(*) FILTER (WHERE COALESCE(state.status, 'no_data') IN (
                    'warning', 'slow', 'degraded'
                )) AS warning,
                count(*) FILTER (WHERE COALESCE(state.status, 'no_data') IN (
                    'critical', 'unavailable', 'problem'
                )) AS critical,
                count(*) FILTER (WHERE COALESCE(state.status, 'no_data') = 'no_data') AS no_data,
                count(*) FILTER (WHERE COALESCE(state.status, 'no_data') = 'paused') AS paused
            FROM websites
            LEFT JOIN website_state AS state ON state.website_id = websites.id
            SQL
        )?->fetch();
        if (!is_array($row)) {
            return ['total' => 0, 'healthy' => 0, 'warning' => 0, 'critical' => 0, 'no_data' => 0, 'paused' => 0];
        }

        return array_map(static fn (mixed $value): int => (int) $value, $row);
    }

    /** @return list<array{id: int, name: string}> */
    public function groups(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $this->pdo->query(
                'SELECT id, name FROM monitoring_groups ORDER BY sort_order, lower(name), id'
            )?->fetchAll() ?? []
        );
    }

    /** @return array<string, mixed>|null */
    public function endpointForCheck(int $endpointId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT endpoints.*, websites.default_interval_seconds, websites.is_active
            FROM website_endpoints AS endpoints
            INNER JOIN websites ON websites.id = endpoints.website_id
            WHERE endpoints.id = :id
            SQL
        );
        $statement->execute(['id' => $endpointId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        $auth = $this->decryptJson($row['auth_encrypted']);
        $headers = $this->decryptJson($row['headers_encrypted']);
        unset($row['auth_encrypted'], $row['headers_encrypted']);
        $row['id'] = (int) $row['id'];
        $row['website_id'] = (int) $row['website_id'];
        $row['auth_username'] = $auth['username'] ?? null;
        $row['auth_secret'] = $auth['secret'] ?? null;
        $row['headers'] = $headers;

        $checks = $this->pdo->prepare(
            'SELECT kind, selector, expected_text, sort_order
             FROM website_content_checks
             WHERE endpoint_id = :endpoint_id
             ORDER BY sort_order, id'
        );
        $checks->execute(['endpoint_id' => $endpointId]);
        $row['content_checks'] = $checks->fetchAll();

        return $row;
    }

    /**
     * @param list<WebsiteEndpointDefinition> $endpoints
     * @return list<array{definition: WebsiteEndpointDefinition, primary: bool}>
     */
    private function primaryEndpoints(array $endpoints, bool $allowIds): array
    {
        if ($endpoints === [] || count($endpoints) > 20) {
            throw new InvalidArgumentException('A website must have between 1 and 20 endpoints.');
        }
        $primaryCount = 0;
        foreach ($endpoints as $endpoint) {
            if (!$endpoint instanceof WebsiteEndpointDefinition) {
                throw new InvalidArgumentException('Website endpoint is invalid.');
            }
            if (!$allowIds && $endpoint->id !== null) {
                throw new InvalidArgumentException('A new website endpoint cannot have an ID.');
            }
            if ($endpoint->isPrimary) {
                $primaryCount++;
            }
        }
        if ($primaryCount > 1) {
            throw new InvalidArgumentException('A website can have only one primary endpoint.');
        }

        $result = [];
        foreach (array_values($endpoints) as $index => $endpoint) {
            $result[] = [
                'definition' => $endpoint,
                'primary' => $primaryCount === 0 ? $index === 0 : $endpoint->isPrimary,
            ];
        }

        return $result;
    }

    private function insertEndpoint(
        int $websiteId,
        WebsiteEndpointDefinition $endpoint,
        bool $primary,
    ): int {
        $auth = $this->encryptedAuth($endpoint, null);
        $headers = $this->encryptedHeaders($endpoint, null);
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO website_endpoints (
                website_id, name, url, is_primary, method, interval_seconds,
                timeout_seconds, follow_redirects, max_redirects,
                status_check_enabled, expected_status_ranges,
                warning_total_ms, critical_total_ms,
                auth_type, auth_encrypted, headers_encrypted,
                credential_redirect_hosts, allow_self_signed, tls_expiry_enabled
            ) VALUES (
                :website_id, :name, :url, :is_primary, :method, :interval_seconds,
                :timeout_seconds, :follow_redirects, :max_redirects,
                :status_check_enabled, CAST(:expected_status_ranges AS jsonb),
                :warning_total_ms, :critical_total_ms,
                :auth_type, :auth_encrypted, :headers_encrypted,
                CAST(:credential_redirect_hosts AS jsonb), :allow_self_signed, :tls_expiry_enabled
            )
            RETURNING id
            SQL
        );
        $statement->execute($this->endpointParameters(
            $websiteId,
            $endpoint,
            $primary,
            $auth,
            $headers
        ));
        $endpointId = (int) $statement->fetchColumn();
        $this->replaceContentChecks($endpointId, $endpoint->contentChecks);
        $this->pdo->prepare(
            'INSERT INTO website_endpoint_state (endpoint_id, website_id)
             VALUES (:endpoint_id, :website_id)'
        )->execute(['endpoint_id' => $endpointId, 'website_id' => $websiteId]);

        return $endpointId;
    }

    /** @param array<string, mixed> $current */
    private function updateEndpoint(
        int $websiteId,
        WebsiteEndpointDefinition $endpoint,
        bool $primary,
        array $current,
    ): void {
        $auth = $this->encryptedAuth($endpoint, $current);
        $headers = $this->encryptedHeaders($endpoint, $current);
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE website_endpoints SET
                name = :name,
                url = :url,
                is_primary = :is_primary,
                method = :method,
                interval_seconds = :interval_seconds,
                timeout_seconds = :timeout_seconds,
                follow_redirects = :follow_redirects,
                max_redirects = :max_redirects,
                status_check_enabled = :status_check_enabled,
                expected_status_ranges = CAST(:expected_status_ranges AS jsonb),
                warning_total_ms = :warning_total_ms,
                critical_total_ms = :critical_total_ms,
                auth_type = :auth_type,
                auth_encrypted = :auth_encrypted,
                headers_encrypted = :headers_encrypted,
                credential_redirect_hosts = CAST(:credential_redirect_hosts AS jsonb),
                allow_self_signed = :allow_self_signed,
                tls_expiry_enabled = :tls_expiry_enabled,
                next_http_check_at = CURRENT_TIMESTAMP
            WHERE id = :id AND website_id = :website_id
            SQL
        );
        $parameters = $this->endpointParameters($websiteId, $endpoint, $primary, $auth, $headers);
        $parameters['id'] = $endpoint->id;
        $statement->execute($parameters);
        $this->replaceContentChecks((int) $endpoint->id, $endpoint->contentChecks);
    }

    /**
     * @param list<array{kind: string, selector: ?string, needle: string}> $checks
     */
    private function replaceContentChecks(int $endpointId, array $checks): void
    {
        $this->pdo->prepare(
            'DELETE FROM website_content_checks WHERE endpoint_id = :endpoint_id'
        )->execute(['endpoint_id' => $endpointId]);
        if ($checks === []) {
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO website_content_checks (
                endpoint_id, kind, selector, expected_text, sort_order
             ) VALUES (
                :endpoint_id, :kind, :selector, :expected_text, :sort_order
             )'
        );
        foreach ($checks as $index => $check) {
            $statement->execute([
                'endpoint_id' => $endpointId,
                'kind' => $check['kind'],
                'selector' => $check['selector'],
                'expected_text' => $check['needle'],
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $current
     */
    private function encryptedAuth(
        WebsiteEndpointDefinition $endpoint,
        ?array $current,
    ): ?string {
        if ($endpoint->authType === 'none') {
            return null;
        }
        if ($endpoint->authSecret === null) {
            if ($current !== null
                && $current['auth_type'] === $endpoint->authType
                && $current['auth_encrypted'] !== null) {
                return $this->databaseBytes($current['auth_encrypted']);
            }
            throw new InvalidArgumentException('Website authentication secret is required.');
        }

        return $this->cipher->encrypt(json_encode([
            'type' => $endpoint->authType,
            'username' => $endpoint->authUsername,
            'secret' => $endpoint->authSecret,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed>|null $current */
    private function encryptedHeaders(
        WebsiteEndpointDefinition $endpoint,
        ?array $current,
    ): ?string
    {
        if (!$endpoint->headersSpecified && $current !== null
            && $current['headers_encrypted'] !== null) {
            return $this->databaseBytes($current['headers_encrypted']);
        }

        return $endpoint->headers === []
            ? null
            : $this->cipher->encrypt(json_encode($endpoint->headers, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function endpointParameters(
        int $websiteId,
        WebsiteEndpointDefinition $endpoint,
        bool $primary,
        ?string $auth,
        ?string $headers,
    ): array {
        return [
            'website_id' => $websiteId,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'is_primary' => $primary ? 1 : 0,
            'method' => $endpoint->method,
            'interval_seconds' => $endpoint->intervalSeconds,
            'timeout_seconds' => $endpoint->timeoutSeconds,
            'follow_redirects' => $endpoint->followRedirects ? 1 : 0,
            'max_redirects' => $endpoint->maxRedirects,
            'status_check_enabled' => $endpoint->statusCheckEnabled ? 1 : 0,
            'expected_status_ranges' => json_encode(
                $endpoint->expectedStatuses->ranges(),
                JSON_THROW_ON_ERROR
            ),
            'warning_total_ms' => $endpoint->warningTotalMs,
            'critical_total_ms' => $endpoint->criticalTotalMs,
            'auth_type' => $endpoint->authType,
            'auth_encrypted' => $auth,
            'headers_encrypted' => $headers,
            'credential_redirect_hosts' => json_encode(
                $endpoint->credentialRedirectHosts,
                JSON_THROW_ON_ERROR
            ),
            'allow_self_signed' => $endpoint->allowSelfSigned ? 1 : 0,
            'tls_expiry_enabled' => $endpoint->tlsExpiryEnabled ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function normalizeSite(array $site): array
    {
        $defaults = $this->settings->websiteDefaults();
        $name = trim((string) ($site['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Website name is invalid.');
        }
        $description = $this->nullableString($site['description'] ?? null, 10000);
        $groupId = $site['group_id'] ?? null;
        if ($groupId === '' || $groupId === null) {
            $groupId = null;
        } else {
            $groupId = filter_var($groupId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($groupId === false) {
                throw new InvalidArgumentException('Website group is invalid.');
            }
        }
        $domainEnabled = $this->bool($site['domain_check_enabled'] ?? false);
        $domain = $this->nullableString($site['registration_domain'] ?? null, 253);
        if ($domain !== null) {
            $domain = strtolower($domain);
            if (preg_match('/[\s\/:@]/', $domain) === 1) {
                throw new InvalidArgumentException('Registration domain is invalid.');
            }
        }
        if ($domainEnabled && $domain === null) {
            throw new InvalidArgumentException('Registration domain is required.');
        }

        $tlsWarning = $this->boundedInt(
            $site['tls_warning_days'] ?? $defaults['website_tls_warning_days'],
            1,
            3650
        );
        $tlsCritical = $this->boundedInt(
            $site['tls_critical_days'] ?? $defaults['website_tls_critical_days'],
            0,
            3650
        );
        $domainWarning = $this->boundedInt(
            $site['domain_warning_days'] ?? $defaults['website_domain_warning_days'],
            1,
            3650
        );
        $domainCritical = $this->boundedInt(
            $site['domain_critical_days'] ?? $defaults['website_domain_critical_days'],
            0,
            3650
        );
        if ($tlsCritical > $tlsWarning || $domainCritical > $domainWarning) {
            throw new InvalidArgumentException('Critical expiry threshold must not exceed warning.');
        }

        return [
            'group_id' => $groupId,
            'name' => $name,
            'description' => $description,
            'registration_domain' => $domain,
            'domain_check_enabled' => $domainEnabled ? 1 : 0,
            'default_interval_seconds' => $this->boundedInt(
                $site['default_interval_seconds'] ?? $defaults['website_default_interval_seconds'],
                10,
                86400
            ),
            'tls_warning_days' => $tlsWarning,
            'tls_critical_days' => $tlsCritical,
            'domain_warning_days' => $domainWarning,
            'domain_critical_days' => $domainCritical,
            'notification_telegram_chat_id' => $this->nullableString(
                $site['notification_telegram_chat_id'] ?? null,
                100
            ),
            'notification_emails' => json_encode(
                $this->emailList($site['notification_emails'] ?? []),
                JSON_THROW_ON_ERROR
            ),
        ];
    }

    /** @param array<string, mixed> $site */
    private function saveSite(int $websiteId, array $site): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE websites SET
                group_id = :group_id,
                name = :name,
                description = :description,
                registration_domain = :registration_domain,
                domain_check_enabled = :domain_check_enabled,
                default_interval_seconds = :default_interval_seconds,
                tls_warning_days = :tls_warning_days,
                tls_critical_days = :tls_critical_days,
                domain_warning_days = :domain_warning_days,
                domain_critical_days = :domain_critical_days,
                notification_telegram_chat_id = :notification_telegram_chat_id,
                notification_emails = CAST(:notification_emails AS jsonb),
                domain_next_check_at = CASE
                    WHEN :domain_check_enabled THEN CURRENT_TIMESTAMP ELSE NULL
                END
            WHERE id = :website_id
            SQL
        );
        $statement->execute([...$site, 'website_id' => $websiteId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSiteRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['group_id'] = $row['group_id'] === null ? null : (int) $row['group_id'];
        $row['domain_check_enabled'] = $this->databaseBool($row['domain_check_enabled']);
        $row['is_active'] = $this->databaseBool($row['is_active']);
        $emails = json_decode((string) $row['notification_emails'], true);
        $row['notification_emails'] = is_array($emails) ? $emails : [];

        return $row;
    }

    /** @return array<string, mixed> */
    private function decryptJson(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        try {
            $json = $this->cipher->decrypt($this->databaseBytes($value));
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Encrypted website configuration is invalid.', 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function databaseBytes(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if ($contents === false) {
                throw new RuntimeException('Cannot read encrypted website configuration.');
            }
            return $contents;
        }
        $value = (string) $value;
        if (str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));
            if ($decoded === false) {
                throw new RuntimeException('Cannot decode encrypted website configuration.');
            }
            return $decoded;
        }

        return $value;
    }

    /** @return list<string> */
    private function emailList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,;\r\n]+/', $value);
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Website notification emails are invalid.');
        }
        $emails = [];
        foreach ($value as $item) {
            $email = trim((string) $item);
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                throw new InvalidArgumentException('Website notification email is invalid.');
            }
            $emails[$email] = true;
        }
        if (count($emails) > 20) {
            throw new InvalidArgumentException('Too many website notification emails.');
        }

        return array_keys($emails);
    }

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);
        if ($result === false) {
            throw new InvalidArgumentException('Website numeric setting is out of range.');
        }

        return $result;
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'on' || $value === 't') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === '' || $value === 'off' || $value === 'f') {
            return false;
        }
        throw new InvalidArgumentException('Website boolean setting is invalid.');
    }

    private function databaseBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Website text setting is too long.');
        }

        return $value;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT website_repository');
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT website_repository');
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT website_repository');
                $this->pdo->exec('RELEASE SAVEPOINT website_repository');
            }
            throw $exception;
        }
    }
}
