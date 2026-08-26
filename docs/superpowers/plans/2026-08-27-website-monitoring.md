# Website Monitoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add production-grade centralized website monitoring with HTTP assertions, performance metrics, TLS and domain-expiry checks, incidents, notifications, shared groups, and a MirvMon-native responsive UI.

**Architecture:** A dedicated website domain is added beside server monitoring. A supervised worker in the existing `app` container claims leased jobs, performs bounded centralized probes outside database transactions, and persists samples plus state transitions transactionally. Websites reuse generalized monitoring groups, alerts, maintenance windows, notification outbox, audit log, and the existing Slim/Twig UI conventions without becoming pseudo-servers.

**Tech Stack:** PHP 8.5, Slim 4, Twig 3, ext-curl, ext-dom, ext-intl, ext-sodium, Symfony CssSelector 8, PostgreSQL 17, TimescaleDB 2.28, Chart.js 4, PHPUnit 13, Docker Compose.

**Scope decomposition:** The feature stays in one plan because source identity, state transitions, incidents and UI all depend on the same schema contract. The 15 tasks are independently reviewable/testable delivery slices, not independent products or partial release targets.

## Global Constraints

- Start execution in an isolated worktree created with `using-git-worktrees`; use branch `codex/website-monitoring` unless the user requests another branch.
- Read `docs/superpowers/specs/2026-08-27-website-monitoring-design.md` before each task and do not broaden its scope.
- Production Compose remains exactly two services: `app` and `db`; all probes run centrally from `app`.
- Do not change the agent protocol or add selected-agent/distributed probes.
- Apply schema changes only through new migrations; never edit migrations `001` through `019`.
- Preserve server IDs, group IDs, alerts, maintenance history, notification history, and audit history during migration.
- Private IPs, localhost, and internal DNS names are valid monitoring targets; only `http` and `https` schemes are accepted and URL credentials are rejected.
- HTTP defaults are 60-second interval, 15-second total deadline, 10 redirects, 1 MiB body limit, `GET`/`HEAD` only, and optional expected-status/content assertions.
- Transport availability and full assertion success are separate values in samples, aggregates, state, and graphs.
- Basic/Bearer secrets and custom-header values use `SecretCipher`; they never appear in rendered HTML, JSON responses, logs, audit metadata, notifications, or exception text.
- Self-signed mode disables only chain/CA trust for the configured hostname; hostname, validity period, handshake, and protocol checks remain mandatory.
- State transitions use three consecutive failures to open and two consecutive successes to close; incident timestamps are backdated to the first sample in each confirming series.
- Raw website samples retain 30 days, hourly aggregates 365 days, daily aggregates indefinitely, and availability/incident history remains long-term.
- All website mutations require authenticated `admin`, CSRF, and audit; authenticated non-admin users retain read-only access.
- UI copy is complete in Russian and English, uses «Что мониторится», introduces no CDN assets, and works at desktop and 390 px.
- Implement with TDD: observe every focused test fail for the intended reason before production code, then pass it before committing.
- A task is not complete if its required Timescale integration test was skipped.
- Do not release a partial MVP; all tasks and final gates must pass before calling the feature production-ready.

## File and Responsibility Map

### Schema and resources

- Create `migrations/020_website_monitoring.sql`: shared group rename, website configuration/state/jobs, generalized alerts/outbox/maintenance, default settings.
- Create `migrations/021_website_timeseries.sql`: website hypertable, aggregates, compression/columnstore, refresh and retention policies.
- Create `resources/domain/iana-rdap-bootstrap.json`: pinned IANA RDAP bootstrap snapshot with source metadata.
- Create `resources/domain/public_suffix_list.dat`: pinned Public Suffix List snapshot and license header.
- Create `resources/domain/whois-profiles.php`: versioned zone-to-server and expiry-field fallback profiles.
- Create `bin/refresh-domain-data`: atomic manual refresh/validation of both bundled snapshots.

### Domain and services

- Create `src/Domain/Websites/WebsiteEndpointDefinition.php`: validated immutable endpoint configuration used by forms and probes.
- Create `src/Domain/Websites/ExpectedStatusSet.php`: parse and evaluate code/range expressions.
- Create `src/Domain/Websites/WebsiteCheckResult.php`: immutable transport/assertion/timing/result contract.
- Create `src/Domain/Websites/WebsiteCheckError.php`: finite safe error categories.
- Create `src/Domain/Websites/WebsiteStateDecision.php`: state transition decision with effective timestamp.
- Create `src/Services/WebsiteEndpointValidator.php`: URL, method, interval, auth, header, redirect and threshold validation.
- Create `src/Services/WebsiteUrlSanitizer.php`: redact sensitive query values for audit, notifications and diagnostics.
- Create `src/Services/WebsiteAssertionEvaluator.php`: expected status, page text and CSS selector evaluation.
- Create `src/Services/WebsiteHttpChecker.php`: bounded ext-curl probing with manual redirects and per-hop timings.
- Create `src/Services/TlsCertificateInspector.php`: hostname/time/chain validation and certificate metadata extraction.
- Create `src/Services/DomainRegistrationChecker.php`: RDAP-first and bounded WHOIS fallback checks.
- Create `src/Services/WebsiteStateEvaluator.php`: pure 3-failure/2-success state machine.
- Create `src/Services/WebsiteIncidentService.php`: transactional states, alerts, availability events and notifications.
- Create `src/Services/WebsiteProbeExecutor.php`: injectable network-execution boundary used by the worker.
- Create `src/Services/CentralWebsiteProbeExecutor.php`: production HTTP/TLS/domain dispatcher.

### Persistence and runtime

- Create `src/Repositories/WebsiteRepository.php`: CRUD, groups, list/detail/dashboard read models, encrypted configuration.
- Create `src/Repositories/WebsiteCheckQueueRepository.php`: due scheduling, manual enqueue, leases and completion.
- Create `src/Repositories/WebsiteMetricsRepository.php`: raw samples, time-series queries and availability timeline.
- Create `src/Repositories/WebsiteTlsRepository.php`: TLS targets, state and certificate-change events.
- Create `src/Repositories/DomainRegistrationRepository.php`: current domain state and daily scheduling.
- Create `src/Repositories/WebsiteAvailabilityRepository.php`: confirmed availability transitions.
- Create `src/Workers/WebsiteCheckWorker.php`: bounded batch orchestration outside transactions.
- Create `bin/website-check-worker`: resilient supervised runtime and heartbeat.
- Modify `src/Repositories/IncidentRepository.php`, `MaintenanceWindowRepository.php`, `NotificationOutboxRepository.php`, and `WorkerHeartbeatRepository.php` to support website sources without changing server behavior.

### HTTP and UI

- Create `src/Controllers/WebsiteController.php`: list/create/edit/delete/pause/resume/manual-check actions.
- Create `src/Controllers/WebsiteDetailController.php`: overview/metrics/events/settings and site maintenance actions.
- Create `src/Controllers/Api/WebsiteMetricsApiController.php`: read-only metrics/status JSON.
- Create `templates/sites/index.twig`, `form.twig`, `create.twig`, `edit.twig`, `detail.twig` and detail partials.
- Create `public/js/sites-form.js` and `public/js/site-detail.js`; modify existing dashboard JS only where website live state requires it.
- Modify dashboard, group, incident, navigation, defaults, CSS and ru/en translation files using existing component conventions.

### Tests and fixtures

- Create focused unit tests under `tests/Unit/Domain/Websites` and `tests/Unit/Services`.
- Create deterministic fixture processes under `tests/Fixtures/Websites` for HTTP, TLS, RDAP and WHOIS; tests never require public internet.
- Create repository/worker/controller integration tests under `tests/Integration`.
- Create `tests/Contract/WebsiteMonitoringContractTest.php` for routes, middleware, assets, worker and secret boundaries.
- Create `bin/benchmark-websites` for 50/1000-site read-model measurements.

---

### Task 1: Pure website input and assertion contracts

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `src/Domain/Websites/ExpectedStatusSet.php`
- Create: `src/Domain/Websites/WebsiteEndpointDefinition.php`
- Create: `src/Domain/Websites/WebsiteCheckError.php`
- Create: `src/Services/WebsiteEndpointValidator.php`
- Create: `src/Services/WebsiteUrlSanitizer.php`
- Create: `src/Services/WebsiteAssertionEvaluator.php`
- Test: `tests/Unit/Domain/Websites/ExpectedStatusSetTest.php`
- Test: `tests/Unit/Services/WebsiteEndpointValidatorTest.php`
- Test: `tests/Unit/Services/WebsiteUrlSanitizerTest.php`
- Test: `tests/Unit/Services/WebsiteAssertionEvaluatorTest.php`

**Interfaces:**
- Produces: `ExpectedStatusSet::fromString(string): self`, `accepts(int): bool`, `ranges(): list<array{min:int,max:int}>`.
- Produces: `WebsiteEndpointValidator::validate(array<string,mixed>): WebsiteEndpointDefinition`.
- Produces: `WebsiteUrlSanitizer::forDisplay(string): string`.
- Produces: `WebsiteAssertionEvaluator::evaluate(int, string, WebsiteEndpointDefinition): array{passed:bool,status_passed:?bool,content_results:list<array{kind:string,passed:bool,safe_message:string}>}`.
- Consumes later: repository forms and `WebsiteHttpChecker` use the immutable definition; no later task reparses raw request input.

- [ ] **Step 1: Add failing status-expression tests**

```php
public function testParsesCodesAndRangesIntoCanonicalIntervals(): void
{
    $set = ExpectedStatusSet::fromString('200, 201-204, 401,403');
    self::assertSame([
        ['min' => 200, 'max' => 200],
        ['min' => 201, 'max' => 204],
        ['min' => 401, 'max' => 401],
        ['min' => 403, 'max' => 403],
    ], $set->ranges());
    self::assertTrue($set->accepts(204));
    self::assertFalse($set->accepts(302));
}

public function testRejectsMalformedOrOutOfRangeStatuses(): void
{
    $this->expectException(InvalidArgumentException::class);
    ExpectedStatusSet::fromString('99,200-700');
}
```

- [ ] **Step 2: Add failing validator tests for the exact security boundary**

```php
#[DataProvider('invalidEndpoints')]
public function testRejectsInvalidEndpointInput(array $input): void
{
    $this->expectException(InvalidArgumentException::class);
    (new WebsiteEndpointValidator())->validate($input);
}

public static function invalidEndpoints(): iterable
{
    yield 'url credentials' => [['url' => 'https://user:pass@example.com']];
    yield 'unsupported scheme' => [['url' => 'file:///etc/passwd']];
    yield 'side-effect method' => [['url' => 'https://example.com', 'method' => 'POST']];
    yield 'too many redirects' => [['url' => 'https://example.com', 'max_redirects' => 11]];
    yield 'forged host' => [['url' => 'https://example.com', 'headers' => [['name' => 'Host', 'value' => 'internal']]]];
}

public function testAllowsPrivateAndInternalTargets(): void
{
    $definition = (new WebsiteEndpointValidator())->validate([
        'name' => 'Intranet',
        'url' => 'https://10.0.0.8/health',
        'method' => 'GET',
        'interval_seconds' => 60,
    ]);
    self::assertSame('https://10.0.0.8/health', $definition->url);
}
```

- [ ] **Step 3: Add failing content-assertion tests**

```php
public function testChecksWholePageTextAndSelectorTextWithoutRegex(): void
{
    $definition = $this->definitionWithChecks([
        ['kind' => 'page_text', 'needle' => 'Service ready'],
        ['kind' => 'css', 'selector' => '#health strong', 'needle' => 'OK'],
    ]);
    $result = (new WebsiteAssertionEvaluator())->evaluate(
        200,
        '<main id="health">Service ready <strong>OK</strong></main>',
        $definition
    );
    self::assertTrue($result['passed']);
    self::assertSame([true, true], array_column($result['content_results'], 'passed'));
}
```

Add `WebsiteUrlSanitizerTest::testRedactsSensitiveQueryValues()` with input
`https://example.com/health?region=eu&access_token=hidden&signature=signed`
and expected output
`https://example.com/health?region=eu&access_token=%5Bredacted%5D&signature=%5Bredacted%5D`.

- [ ] **Step 4: Run the focused tests and confirm the missing-class failures**

Run: `vendor/bin/phpunit tests/Unit/Domain/Websites/ExpectedStatusSetTest.php tests/Unit/Services/WebsiteEndpointValidatorTest.php tests/Unit/Services/WebsiteUrlSanitizerTest.php tests/Unit/Services/WebsiteAssertionEvaluatorTest.php`

Expected: FAIL because the website domain classes do not exist.

- [ ] **Step 5: Install the selector dependency and implement the contracts**

Run: `composer require symfony/css-selector:^8.0 --no-interaction`

Implement the immutable boundary with this public shape:

```php
final readonly class WebsiteEndpointDefinition
{
    /** @param list<array{kind:string,selector:?string,needle:string}> $contentChecks
     *  @param array<string,string> $headers
     *  @param list<string> $credentialRedirectHosts */
    public function __construct(
        public string $name,
        public string $url,
        public string $method,
        public int $intervalSeconds,
        public int $timeoutSeconds,
        public bool $followRedirects,
        public int $maxRedirects,
        public bool $statusCheckEnabled,
        public ExpectedStatusSet $expectedStatuses,
        public array $contentChecks,
        public ?int $warningTotalMs,
        public ?int $criticalTotalMs,
        public string $authType,
        public ?string $authUsername,
        public ?string $authSecret,
        public array $headers,
        public array $credentialRedirectHosts,
        public bool $allowSelfSigned,
        public bool $tlsExpiryEnabled,
    ) {}
}
```

`WebsiteEndpointValidator` must canonicalize scheme/host case, preserve path/query, reject fragments and credentials, permit `GET`/`HEAD`, enforce interval `10..86400`, timeout `1..60`, redirects `0..10`, body-compatible content checks only with `GET`, and response thresholds `1..60000` with critical not below warning.

Header names are compared case-insensitively. Permit standard negotiation/client headers plus names beginning `X-`, `Api-Key`, and `Idempotency-Key`. Reject `Host`, `Cookie`, `Content-Length`, `Connection`, `Transfer-Encoding`, `Forwarded`, every `Proxy-*`/`Sec-*`, and arbitrary `Authorization`; Basic/Bearer fields are the only authorization input. Limit to 20 headers, 200-byte names and 8192-byte values.

`WebsiteUrlSanitizer` removes userinfo/fragment and replaces query values with `[redacted]` when the decoded key contains `token`, `key`, `secret`, `password`, `auth`, or `signature` case-insensitively. It preserves nonsensitive query keys so operators can identify the checked resource.

`WebsiteAssertionEvaluator` must load HTML with internal libxml errors, convert CSS through Symfony CssSelector, use literal Unicode substring matching, cap checks at 20, and return safe messages without copying page text.

Implement the complete error enum used by every later task:

```php
enum WebsiteCheckError: string
{
    case Dns = 'dns';
    case Connect = 'connect';
    case Timeout = 'timeout';
    case Tls = 'tls';
    case RedirectLoop = 'redirect_loop';
    case RedirectLimit = 'redirect_limit';
    case RedirectScheme = 'redirect_scheme';
    case UnexpectedStatus = 'unexpected_status';
    case ContentMissing = 'content_missing';
    case SlowResponse = 'slow_response';
    case ResponseTooLarge = 'response_too_large';
    case Internal = 'internal_checker';
}
```

- [ ] **Step 6: Run focused tests and static analysis**

Run: `vendor/bin/phpunit tests/Unit/Domain/Websites/ExpectedStatusSetTest.php tests/Unit/Services/WebsiteEndpointValidatorTest.php tests/Unit/Services/WebsiteUrlSanitizerTest.php tests/Unit/Services/WebsiteAssertionEvaluatorTest.php && composer analyse`

Expected: PASS with no PHPStan errors.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock src/Domain/Websites src/Services/WebsiteEndpointValidator.php src/Services/WebsiteUrlSanitizer.php src/Services/WebsiteAssertionEvaluator.php tests/Unit/Domain/Websites tests/Unit/Services/WebsiteEndpointValidatorTest.php tests/Unit/Services/WebsiteUrlSanitizerTest.php tests/Unit/Services/WebsiteAssertionEvaluatorTest.php
git commit -m "feat: add website check contracts"
```

### Task 2: Shared groups and regular website schema

**Files:**
- Create: `migrations/020_website_monitoring.sql`
- Modify: `src/Controllers/GroupController.php`
- Modify: `src/Controllers/ServerController.php`
- Modify: `src/Middlewares/AuditTrailMiddleware.php`
- Modify: `src/Repositories/IncidentRepository.php`
- Modify: `src/Repositories/ServerRepository.php`
- Modify: `tests/Integration/Controllers/GroupControllerTest.php`
- Modify: `tests/Integration/Controllers/ServerControllerTest.php`
- Modify: `tests/Integration/Repositories/IncidentRepositoryTest.php`
- Modify: `tests/Integration/Database/SchemaTest.php`
- Test: `tests/Integration/Database/WebsiteSchemaTest.php`

**Interfaces:**
- Produces: `monitoring_groups` as the only physical group table; existing server FKs follow the rename.
- Produces: configuration, current-state, job, TLS/domain and event tables consumed by all later tasks.
- Produces: generalized `alerts`, `notification_outbox`, and `maintenance_windows` with exactly one server/website source.

- [ ] **Step 1: Add failing migration inventory and invariant tests**

```php
public function testWebsiteTablesAndGeneralizedSourcesExist(): void
{
    self::assertSame('monitoring_groups', $this->table('monitoring_groups'));
    self::assertSame(null, $this->table('server_groups'));
    foreach (['websites', 'website_endpoints', 'website_content_checks',
        'website_endpoint_state', 'website_state', 'website_tls_targets',
        'website_tls_state', 'website_certificate_events',
        'website_domain_state', 'website_check_jobs',
        'website_availability_events'] as $table) {
        self::assertSame($table, $this->table($table));
    }
}

public function testAlertRejectsZeroOrTwoSourceKinds(): void
{
    $this->expectException(PDOException::class);
    self::$pdo->exec("INSERT INTO alerts(kind,severity) VALUES ('website_http','critical')");
}
```

Also assert one primary endpoint per site, endpoint ownership on website alerts,
job target-kind constraints, maintenance source XOR, outbox source XOR when a
source is present, and encrypted columns without plaintext equivalents.

- [ ] **Step 2: Run the schema tests and confirm migration 020 is missing**

Run: `vendor/bin/phpunit tests/Integration/Database/SchemaTest.php tests/Integration/Database/WebsiteSchemaTest.php`

Expected: FAIL because migration `020_website_monitoring.sql` and its tables do not exist; a skip is not acceptable.

- [ ] **Step 3: Implement migration 020**

The migration must perform these operations in order:

```sql
ALTER TABLE server_groups RENAME TO monitoring_groups;

CREATE TABLE websites (
    id BIGSERIAL PRIMARY KEY,
    group_id BIGINT REFERENCES monitoring_groups(id) ON DELETE SET NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    registration_domain VARCHAR(253),
    domain_check_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    default_interval_seconds INTEGER NOT NULL DEFAULT 60 CHECK (default_interval_seconds BETWEEN 10 AND 86400),
    tls_warning_days INTEGER NOT NULL DEFAULT 21 CHECK (tls_warning_days BETWEEN 1 AND 3650),
    tls_critical_days INTEGER NOT NULL DEFAULT 7 CHECK (tls_critical_days BETWEEN 0 AND 3650),
    domain_warning_days INTEGER NOT NULL DEFAULT 30 CHECK (domain_warning_days BETWEEN 1 AND 3650),
    domain_critical_days INTEGER NOT NULL DEFAULT 7 CHECK (domain_critical_days BETWEEN 0 AND 3650),
    notification_telegram_chat_id VARCHAR(100),
    notification_emails JSONB NOT NULL DEFAULT '[]'::jsonb,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    paused_at TIMESTAMPTZ,
    domain_next_check_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (domain_check_enabled = FALSE OR registration_domain IS NOT NULL),
    CHECK (tls_critical_days <= tls_warning_days),
    CHECK (domain_critical_days <= domain_warning_days)
);

CREATE TABLE website_endpoints (
    id BIGSERIAL PRIMARY KEY,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    url TEXT NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    method VARCHAR(4) NOT NULL DEFAULT 'GET' CHECK (method IN ('GET','HEAD')),
    interval_seconds INTEGER CHECK (interval_seconds BETWEEN 10 AND 86400),
    timeout_seconds INTEGER NOT NULL DEFAULT 15 CHECK (timeout_seconds BETWEEN 1 AND 60),
    follow_redirects BOOLEAN NOT NULL DEFAULT TRUE,
    max_redirects SMALLINT NOT NULL DEFAULT 10 CHECK (max_redirects BETWEEN 0 AND 10),
    status_check_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    expected_status_ranges JSONB NOT NULL DEFAULT '[{"min":200,"max":299}]'::jsonb,
    warning_total_ms INTEGER CHECK (warning_total_ms BETWEEN 1 AND 60000),
    critical_total_ms INTEGER CHECK (critical_total_ms BETWEEN 1 AND 60000),
    auth_type VARCHAR(10) NOT NULL DEFAULT 'none' CHECK (auth_type IN ('none','basic','bearer')),
    auth_encrypted BYTEA,
    headers_encrypted BYTEA,
    credential_redirect_hosts JSONB NOT NULL DEFAULT '[]'::jsonb,
    allow_self_signed BOOLEAN NOT NULL DEFAULT FALSE,
    tls_expiry_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    next_http_check_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (critical_total_ms IS NULL OR warning_total_ms IS NULL OR critical_total_ms >= warning_total_ms)
);

CREATE UNIQUE INDEX website_endpoints_one_primary_idx
    ON website_endpoints(website_id) WHERE is_primary = TRUE;

ALTER TABLE website_endpoints ADD CONSTRAINT website_endpoints_id_site_unique UNIQUE (id, website_id);

CREATE TABLE website_content_checks (
    id BIGSERIAL PRIMARY KEY,
    endpoint_id BIGINT NOT NULL REFERENCES website_endpoints(id) ON DELETE CASCADE,
    kind VARCHAR(20) NOT NULL CHECK (kind IN ('page_text','css')),
    selector VARCHAR(1000),
    expected_text VARCHAR(2000) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    CHECK ((kind = 'page_text' AND selector IS NULL) OR (kind = 'css' AND selector IS NOT NULL))
);

CREATE TABLE website_endpoint_state (
    endpoint_id BIGINT PRIMARY KEY,
    website_id BIGINT NOT NULL,
    transport_state VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (transport_state IN ('no_data','healthy','possible_problem','problem','recovering','paused')),
    assertion_state VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (assertion_state IN ('no_data','healthy','possible_problem','problem','recovering','paused')),
    performance_state VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (performance_state IN ('no_data','healthy','possible_problem','problem','recovering','paused')),
    performance_severity VARCHAR(10) CHECK (performance_severity IN ('warning','critical')),
    transport_failures SMALLINT NOT NULL DEFAULT 0,
    transport_successes SMALLINT NOT NULL DEFAULT 0,
    assertion_failures SMALLINT NOT NULL DEFAULT 0,
    assertion_successes SMALLINT NOT NULL DEFAULT 0,
    performance_failures SMALLINT NOT NULL DEFAULT 0,
    performance_successes SMALLINT NOT NULL DEFAULT 0,
    transport_series_started_at TIMESTAMPTZ,
    assertion_series_started_at TIMESTAMPTZ,
    performance_series_started_at TIMESTAMPTZ,
    last_sample_at TIMESTAMPTZ,
    last_status_code SMALLINT,
    last_final_url TEXT,
    last_redirect_count SMALLINT NOT NULL DEFAULT 0,
    last_ttfb_ms DOUBLE PRECISION,
    last_total_ms DOUBLE PRECISION,
    last_error_kind VARCHAR(40),
    last_safe_message VARCHAR(1000),
    FOREIGN KEY (endpoint_id, website_id) REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE
);

CREATE TABLE website_state (
    website_id BIGINT PRIMARY KEY REFERENCES websites(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (status IN ('healthy','unavailable','problem','degraded','slow','warning','critical','no_data','paused')),
    primary_endpoint_id BIGINT,
    active_problem_count INTEGER NOT NULL DEFAULT 0,
    possible_problem_text VARCHAR(80),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (primary_endpoint_id, website_id) REFERENCES website_endpoints(id, website_id)
);

CREATE TABLE website_tls_targets (
    id BIGSERIAL PRIMARY KEY,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    endpoint_id BIGINT NOT NULL,
    hostname VARCHAR(253) NOT NULL,
    port INTEGER NOT NULL CHECK (port BETWEEN 1 AND 65535),
    source VARCHAR(12) NOT NULL CHECK (source IN ('configured','redirect')),
    allow_self_signed BOOLEAN NOT NULL DEFAULT FALSE,
    next_check_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (endpoint_id, website_id) REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE,
    UNIQUE (endpoint_id, hostname, port),
    UNIQUE (id, website_id),
    CHECK (source = 'configured' OR allow_self_signed = FALSE)
);

CREATE TABLE website_tls_state (
    tls_target_id BIGINT PRIMARY KEY REFERENCES website_tls_targets(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (status IN ('no_data','healthy','warning','critical','error')),
    subject VARCHAR(1000),
    issuer VARCHAR(1000),
    sans JSONB NOT NULL DEFAULT '[]'::jsonb,
    fingerprint_sha256 CHAR(64),
    not_before TIMESTAMPTZ,
    not_after TIMESTAMPTZ,
    error_kind VARCHAR(40),
    checked_at TIMESTAMPTZ,
    retry_count SMALLINT NOT NULL DEFAULT 0
);

CREATE TABLE website_certificate_events (
    id BIGSERIAL PRIMARY KEY,
    tls_target_id BIGINT NOT NULL REFERENCES website_tls_targets(id) ON DELETE CASCADE,
    previous_fingerprint_sha256 CHAR(64),
    fingerprint_sha256 CHAR(64) NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE TABLE website_domain_state (
    website_id BIGINT PRIMARY KEY REFERENCES websites(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (status IN ('no_data','healthy','warning','critical','unsupported','unknown')),
    expires_at TIMESTAMPTZ,
    registrar VARCHAR(500),
    source VARCHAR(20),
    error_kind VARCHAR(40),
    checked_at TIMESTAMPTZ,
    retry_count SMALLINT NOT NULL DEFAULT 0
);

CREATE TABLE website_check_jobs (
    id BIGSERIAL PRIMARY KEY,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    endpoint_id BIGINT,
    tls_target_id BIGINT,
    kind VARCHAR(10) NOT NULL CHECK (kind IN ('http','tls','domain')),
    state VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','leased')),
    manual BOOLEAN NOT NULL DEFAULT FALSE,
    priority SMALLINT NOT NULL DEFAULT 0,
    scheduled_for TIMESTAMPTZ NOT NULL,
    available_at TIMESTAMPTZ NOT NULL,
    lease_owner VARCHAR(80),
    lease_until TIMESTAMPTZ,
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 10),
    safe_error_kind VARCHAR(40),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (endpoint_id, website_id) REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE,
    FOREIGN KEY (tls_target_id, website_id) REFERENCES website_tls_targets(id, website_id) ON DELETE CASCADE,
    CHECK (
        (kind = 'http' AND endpoint_id IS NOT NULL AND tls_target_id IS NULL)
        OR (kind = 'tls' AND endpoint_id IS NULL AND tls_target_id IS NOT NULL)
        OR (kind = 'domain' AND endpoint_id IS NULL AND tls_target_id IS NULL)
    ),
    CHECK (priority BETWEEN 0 AND 100),
    CHECK ((state = 'pending' AND lease_owner IS NULL AND lease_until IS NULL)
        OR (state = 'leased' AND lease_owner IS NOT NULL AND lease_until IS NOT NULL))
);

CREATE TABLE website_availability_events (
    id BIGSERIAL PRIMARY KEY,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    endpoint_id BIGINT NOT NULL,
    state VARCHAR(12) NOT NULL CHECK (state IN ('available','unavailable')),
    occurred_at TIMESTAMPTZ NOT NULL,
    alert_id BIGINT REFERENCES alerts(id) ON DELETE SET NULL,
    FOREIGN KEY (endpoint_id, website_id) REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE
);
```

Store encrypted values only in `BYTEA`. Add updated-at triggers and lookup indexes for every FK/due/active query. Coalesce duplicate scheduled/manual target jobs with:

```sql
CREATE UNIQUE INDEX website_check_jobs_dedupe_idx
    ON website_check_jobs(
        website_id,
        kind,
        COALESCE(endpoint_id, 0),
        COALESCE(tls_target_id, 0),
        scheduled_for,
        manual
    )
    WHERE state IN ('pending','leased');
```

Before creating `website_availability_events`, generalize alerts with exact source constraints:

```sql
ALTER TABLE alerts ALTER COLUMN server_id DROP NOT NULL;
ALTER TABLE alerts
    ADD COLUMN website_id BIGINT REFERENCES websites(id) ON DELETE CASCADE,
    ADD COLUMN endpoint_id BIGINT,
    ADD COLUMN details JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN resolution_reason VARCHAR(40),
    DROP CONSTRAINT alerts_kind_check,
    ADD CONSTRAINT alerts_kind_check CHECK (kind IN (
        'metric','service','offline','website_http','website_assertion',
        'website_performance','website_tls','website_domain'
    )),
    ADD CONSTRAINT alerts_source_check CHECK (
        (server_id IS NOT NULL)::integer + (website_id IS NOT NULL)::integer = 1
    ),
    ADD CONSTRAINT alerts_endpoint_site_fk FOREIGN KEY (endpoint_id, website_id)
        REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE,
    ADD CONSTRAINT alerts_endpoint_source_check CHECK (
        endpoint_id IS NULL OR website_id IS NOT NULL
    );

CREATE UNIQUE INDEX alerts_one_active_website_kind_idx
    ON alerts(website_id, COALESCE(endpoint_id, 0), kind)
    WHERE resolved = FALSE AND website_id IS NOT NULL;

ALTER TABLE notification_outbox
    ADD COLUMN website_id BIGINT REFERENCES websites(id) ON DELETE SET NULL,
    ADD CONSTRAINT notification_outbox_source_check CHECK (
        server_id IS NULL OR website_id IS NULL
    );

ALTER TABLE maintenance_windows ALTER COLUMN server_id DROP NOT NULL;
ALTER TABLE maintenance_windows
    ADD COLUMN website_id BIGINT REFERENCES websites(id) ON DELETE CASCADE,
    ADD CONSTRAINT maintenance_windows_source_check CHECK (
        (server_id IS NOT NULL)::integer + (website_id IS NOT NULL)::integer = 1
    );
```

Outbox allows neither source only for existing transport-test messages; it never allows both. Add source-specific cooldown/active indexes. Insert app settings for interval `60`, HTTP timeout `15`, TLS `21/7`, domain `30/7`, and website worker concurrency `10`.

- [ ] **Step 4: Replace physical group-table references in current code and tests**

Run: `rg -l 'server_groups' src tests bin | xargs sed -i 's/server_groups/monitoring_groups/g'`

Review every resulting diff; only SQL identifiers change. Do not edit `migrations/001_initial.sql`, because applied migrations are checksum protected.

- [ ] **Step 5: Run migration and current regression tests**

Run: `vendor/bin/phpunit tests/Integration/Database/SchemaTest.php tests/Integration/Database/WebsiteSchemaTest.php tests/Integration/Controllers/GroupControllerTest.php tests/Integration/Controllers/ServerControllerTest.php tests/Integration/Repositories/IncidentRepositoryTest.php`

Expected: PASS against a freshly migrated TimescaleDB, with all existing group/server rows still readable.

- [ ] **Step 6: Commit**

```bash
git add migrations/020_website_monitoring.sql src/Controllers/GroupController.php src/Controllers/ServerController.php src/Middlewares/AuditTrailMiddleware.php src/Repositories/IncidentRepository.php src/Repositories/ServerRepository.php tests/Integration
git commit -m "feat: add website monitoring schema"
```

### Task 3: Website time-series storage and queries

**Files:**
- Create: `migrations/021_website_timeseries.sql`
- Create: `src/Domain/Websites/WebsiteCheckResult.php`
- Create: `src/Repositories/WebsiteMetricsRepository.php`
- Modify: `tests/Integration/Database/SchemaTest.php`
- Test: `tests/Integration/Repositories/WebsiteMetricsRepositoryTest.php`
- Test: `tests/Unit/Domain/Websites/WebsiteCheckResultTest.php`

**Interfaces:**
- Produces: `WebsiteMetricsRepository::record(WebsiteCheckResult): void`.
- Produces: `series(int $websiteId, ?int $endpointId, DateTimeImmutable $from, DateTimeImmutable $to): array{source:string,bucket_seconds:int,points:list<array<string,mixed>>}`.
- Produces: `latest(int $websiteId): list<array<string,mixed>>` and raw diagnostics lookup by sample UUID.

- [ ] **Step 1: Add failing sample-contract and repository tests**

```php
public function testResultSeparatesAvailabilityFromAssertions(): void
{
    $result = new WebsiteCheckResult(
        websiteId: 7,
        endpointId: 9,
        sampleId: '10000000-0000-4000-8000-000000000009',
        checkedAt: new DateTimeImmutable('2026-08-27T00:00:00Z'),
        transportAvailable: true,
        assertionsPassed: false,
        statusCode: 503,
        configuredUrl: 'https://example.com/',
        finalUrl: 'https://example.com/',
        redirectChain: [],
        timings: ['dns_ms'=>1.0,'tcp_ms'=>2.0,'tls_ms'=>3.0,'ttfb_ms'=>20.0,'total_ms'=>25.0],
        error: WebsiteCheckError::UnexpectedStatus,
        assertionResults: [['kind'=>'status','passed'=>false,'safe_message'=>'expected 2xx, got 503']],
        manual: false,
    );
    self::assertTrue($result->transportAvailable);
    self::assertFalse($result->assertionsPassed);
}
```

Implement `WebsiteCheckResult` with this immutable shape:

```php
final readonly class WebsiteCheckResult
{
    /** @param list<array<string,mixed>> $redirectChain
     *  @param array{dns_ms:?float,tcp_ms:?float,tls_ms:?float,ttfb_ms:?float,total_ms:?float} $timings
     *  @param list<array{kind:string,passed:bool,safe_message:string}> $assertionResults */
    public function __construct(
        public int $websiteId,
        public int $endpointId,
        public string $sampleId,
        public DateTimeImmutable $checkedAt,
        public bool $transportAvailable,
        public bool $assertionsPassed,
        public ?int $statusCode,
        public string $configuredUrl,
        public ?string $finalUrl,
        public array $redirectChain,
        public array $timings,
        public ?WebsiteCheckError $error,
        public array $assertionResults,
        public bool $manual,
        public string $probeKind = 'app',
        public ?string $probeId = null,
    ) {}
}
```

Repository tests insert available/assertion-failed/unavailable samples and assert separate ratios, min/avg/max timing, final URL/count persistence, full redirect details only for failed/manual/changed samples, and no response-body column.

- [ ] **Step 2: Run focused tests and confirm missing migration/repository failures**

Run: `vendor/bin/phpunit tests/Unit/Domain/Websites/WebsiteCheckResultTest.php tests/Integration/Repositories/WebsiteMetricsRepositoryTest.php tests/Integration/Database/SchemaTest.php`

Expected: FAIL because migration 021, DTO and repository do not exist; integration must not be skipped.

- [ ] **Step 3: Implement the hypertable and aggregates**

```sql
CREATE TABLE website_check_samples (
    sample_time TIMESTAMPTZ NOT NULL,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    endpoint_id BIGINT NOT NULL REFERENCES website_endpoints(id) ON DELETE CASCADE,
    sample_id UUID NOT NULL,
    probe_kind VARCHAR(10) NOT NULL DEFAULT 'app' CHECK (probe_kind = 'app'),
    probe_id VARCHAR(100),
    manual BOOLEAN NOT NULL DEFAULT FALSE,
    transport_available BOOLEAN NOT NULL,
    assertions_passed BOOLEAN NOT NULL,
    status_code SMALLINT CHECK (status_code BETWEEN 100 AND 599),
    configured_url TEXT NOT NULL,
    final_url TEXT,
    redirect_count SMALLINT NOT NULL DEFAULT 0 CHECK (redirect_count BETWEEN 0 AND 10),
    dns_ms DOUBLE PRECISION,
    tcp_ms DOUBLE PRECISION,
    tls_ms DOUBLE PRECISION,
    ttfb_ms DOUBLE PRECISION,
    total_ms DOUBLE PRECISION,
    error_kind VARCHAR(40),
    diagnostics JSONB NOT NULL DEFAULT '{}'::jsonb,
    PRIMARY KEY (sample_time, endpoint_id, sample_id),
    CHECK (probe_kind <> 'app' OR probe_id IS NULL)
);

SELECT create_hypertable('website_check_samples', by_range('sample_time', INTERVAL '1 day'), if_not_exists => TRUE, create_default_indexes => FALSE);
CREATE INDEX website_check_samples_lookup_idx ON website_check_samples(website_id, endpoint_id, sample_time DESC);
```

Create hourly/daily continuous aggregates grouped by `website_id, endpoint_id`, with counts, `avg(transport_available::int)`, `avg(assertions_passed::int)`, and min/avg/max TTFB/total. Add refresh policies, columnstore after 7 days, raw retention after 30 days, hourly retention after 365 days, and no daily retention policy.

- [ ] **Step 4: Implement DTO and repository with source selection**

Use raw rows for ranges up to 24 hours, hourly for ranges up to 30 days, and daily beyond 30 days. Cast all database numerics/booleans at the repository boundary. Diagnostics allow only error category, safe message, assertion booleans, redirect hop URL/code/timing, and certificate metadata; reject a `body`, `headers`, `token`, `password`, or `authorization` key before insert.

- [ ] **Step 5: Run focused tests**

Run: `vendor/bin/phpunit tests/Unit/Domain/Websites/WebsiteCheckResultTest.php tests/Integration/Repositories/WebsiteMetricsRepositoryTest.php tests/Integration/Database/SchemaTest.php`

Expected: PASS and Timescale jobs show raw 30-day/hourly 365-day retention with no daily retention job.

- [ ] **Step 6: Commit**

```bash
git add migrations/021_website_timeseries.sql src/Domain/Websites/WebsiteCheckResult.php src/Repositories/WebsiteMetricsRepository.php tests/Unit/Domain/Websites/WebsiteCheckResultTest.php tests/Integration/Repositories/WebsiteMetricsRepositoryTest.php tests/Integration/Database/SchemaTest.php
git commit -m "feat: store website check metrics"
```

### Task 4: Website CRUD repository, settings, and encrypted configuration

**Files:**
- Create: `src/Repositories/WebsiteRepository.php`
- Modify: `src/Repositories/AppSettingsRepository.php`
- Modify: `src/Controllers/AdminController.php`
- Modify: `templates/admin/defaults.twig`
- Modify: `translations/ru.php`
- Modify: `translations/en.php`
- Test: `tests/Integration/Repositories/WebsiteRepositoryTest.php`
- Modify: `tests/Integration/Controllers/AdminControllerTest.php`
- Modify: `tests/Unit/Security/SecretCipherTest.php`

**Interfaces:**
- Produces: `create(array $site, list<WebsiteEndpointDefinition> $endpoints): int`, `update(int, array, list<WebsiteEndpointDefinition>): void`, `delete(int): void`, `pause(int, DateTimeImmutable): void`, `resume(int, DateTimeImmutable): void`.
- Produces: `find(int): ?array`, `detail(int): ?array`, `groupedList(array $filters): array`, `dashboardSummary(): array`, `groups(): list<array{id:int,name:string}>`.
- Produces: `endpointForCheck(int): ?array` returning decrypted secrets only to worker code.

- [ ] **Step 1: Add failing transactional CRUD and secret tests**

```php
public function testCreatesSiteWithExactlyOnePrimaryAndEncryptedSecrets(): void
{
    $siteId = $this->repository->create(
        ['name'=>'Portal','group_id'=>$this->groupId,'registration_domain'=>'example.com','domain_check_enabled'=>true],
        [$this->endpoint(authSecret: 'bearer-secret', headers: ['X-Api-Key'=>'header-secret'])]
    );
    self::assertNotNull($this->repository->find($siteId));
    self::assertSame(1, $this->countPrimary($siteId));
    $stored = $this->rawEndpoint($siteId);
    self::assertStringNotContainsString('bearer-secret', $stored['auth_encrypted']);
    self::assertStringNotContainsString('header-secret', $stored['headers_encrypted']);
}

public function testUpdateRollsBackWhenRemovingOnlyPrimaryEndpoint(): void
{
    $this->expectException(InvalidArgumentException::class);
    $this->repository->update($this->siteId, ['name'=>'Portal'], []);
}
```

Cover omitted secret = keep current, explicit clear = remove, endpoint ownership, normalized-domain persistence, domain required only when enabled, groups with only sites, pause closing schedule, resume setting due-now, list filters and bounded query count. Domain normalization itself is introduced and tested in Task 8.

- [ ] **Step 2: Run tests and confirm repository/default failures**

Run: `vendor/bin/phpunit tests/Integration/Repositories/WebsiteRepositoryTest.php tests/Integration/Controllers/AdminControllerTest.php tests/Unit/Security/SecretCipherTest.php`

Expected: FAIL because repository and website defaults do not exist.

- [ ] **Step 3: Implement repository transactions and secret handling**

Use PDO transactions/savepoints matching existing repositories. Encrypt the complete Basic/Bearer credential JSON, including Basic username, into `auth_encrypted`; encrypt the complete name/value header map separately. Store `SecretCipher::encrypt()` output as bytes in `BYTEA`; decode PDO resource/hex values before decrypting. Never include decrypted fields in `find()`, `detail()`, list or dashboard rows. Only `endpointForCheck()` may return:

```php
[
    'auth_type' => 'bearer',
    'auth_username' => null,
    'auth_secret' => 'decrypted-at-worker-boundary',
    'headers' => ['X-Api-Key' => 'decrypted-at-worker-boundary'],
]
```

Create/update endpoints and content checks in one transaction, lock current endpoints during primary replacement, and schedule changed/new endpoints immediately. Pause marks state paused and removes pending jobs; resume resets current state to no-data and schedules immediately without creating recovery events.

- [ ] **Step 4: Add validated global defaults to admin settings**

Add fields for interval 60, timeout 15, TLS warning/critical 21/7, domain warning/critical 30/7, and worker concurrency 10. Validate intervals `10..86400`, timeout `1..60`, days `0..3650` with critical no greater than warning, concurrency `1..50`. Render and translate every field in ru/en.

- [ ] **Step 5: Run focused tests and PHPStan**

Run: `vendor/bin/phpunit tests/Integration/Repositories/WebsiteRepositoryTest.php tests/Integration/Controllers/AdminControllerTest.php tests/Unit/Security/SecretCipherTest.php && composer analyse`

Expected: PASS with no plaintext secret in query results or rendered defaults.

- [ ] **Step 6: Commit**

```bash
git add src/Repositories/WebsiteRepository.php src/Repositories/AppSettingsRepository.php src/Controllers/AdminController.php templates/admin/defaults.twig translations/ru.php translations/en.php tests/Integration/Repositories/WebsiteRepositoryTest.php tests/Integration/Controllers/AdminControllerTest.php tests/Unit/Security/SecretCipherTest.php
git commit -m "feat: persist website configurations"
```

### Task 5: Durable scheduling, manual priority, and leases

**Files:**
- Create: `src/Repositories/WebsiteCheckQueueRepository.php`
- Test: `tests/Integration/Repositories/WebsiteCheckQueueRepositoryTest.php`

**Interfaces:**
- Produces: `scheduleDue(DateTimeImmutable $now, int $limit = 100): int`.
- Produces: `enqueueManual(int $websiteId, DateTimeImmutable $now): int`.
- Produces: `claim(string $leaseOwner, DateTimeImmutable $now, int $limit): list<array<string,mixed>>`.
- Produces: `complete(int $jobId, string $leaseOwner): void`, `release(int $jobId, string $leaseOwner, DateTimeImmutable $availableAt, string $safeError): void`.

- [ ] **Step 1: Add failing queue concurrency tests**

```php
public function testTwoClaimersNeverReceiveTheSameJob(): void
{
    $this->queue->scheduleDue(new DateTimeImmutable('2026-08-27T00:00:00Z'));
    $first = $this->queue->claim('worker-a', new DateTimeImmutable('2026-08-27T00:00:01Z'), 10);
    $second = $this->queue->claim('worker-b', new DateTimeImmutable('2026-08-27T00:00:01Z'), 10);
    self::assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
}

public function testManualJobsSortBeforeScheduledAndExpiredLeaseIsReclaimed(): void
{
    $this->queue->scheduleDue($this->now);
    $this->queue->enqueueManual($this->siteId, $this->now);
    $claimed = $this->queue->claim('worker-a', $this->now, 1);
    self::assertTrue($claimed[0]['manual']);
    $reclaimed = $this->queue->claim('worker-b', $this->now->modify('+61 seconds'), 1);
    self::assertSame($claimed[0]['id'], $reclaimed[0]['id']);
}
```

Also cover coalescing repeated manual clicks, no jobs for paused sites, one catch-up job rather than every missed interval, daily TLS/domain scheduling, safe retry backoff and target-kind constraints.

- [ ] **Step 2: Run the queue tests and confirm the missing repository failure**

Run: `vendor/bin/phpunit tests/Integration/Repositories/WebsiteCheckQueueRepositoryTest.php`

Expected: FAIL because `WebsiteCheckQueueRepository` does not exist; a skip is not acceptable.

- [ ] **Step 3: Implement short scheduling and claim transactions**

`scheduleDue()` locks due endpoint/TLS/domain targets with `FOR UPDATE SKIP LOCKED`, inserts one job per target, and advances next-check timestamps before commit. `claim()` uses one CTE:

```sql
WITH claimable AS (
    SELECT id
    FROM website_check_jobs
    WHERE available_at <= :now
      AND (state = 'pending' OR lease_until < :now)
    ORDER BY manual DESC, priority DESC, available_at, id
    FOR UPDATE SKIP LOCKED
    LIMIT :limit
)
UPDATE website_check_jobs AS jobs
SET state = 'leased', lease_owner = :lease_owner,
    lease_until = :lease_until, attempts = attempts + 1
FROM claimable
WHERE jobs.id = claimable.id
RETURNING jobs.*;
```

Use a 60-second lease, cap attempts at 10, exponential retry capped at one hour, and retain only a safe error category. `complete()` deletes the leased job after its result transaction commits. Manual enqueue creates high-priority HTTP jobs for all endpoints plus enabled TLS/domain jobs and coalesces existing pending manual jobs.

- [ ] **Step 4: Run focused tests**

Run: `vendor/bin/phpunit tests/Integration/Repositories/WebsiteCheckQueueRepositoryTest.php`

Expected: PASS including the two-connection concurrent claim case.

- [ ] **Step 5: Commit**

```bash
git add src/Repositories/WebsiteCheckQueueRepository.php tests/Integration/Repositories/WebsiteCheckQueueRepositoryTest.php
git commit -m "feat: schedule website checks safely"
```

### Task 6: Bounded HTTP probing, redirects, timings, and body assertions

**Files:**
- Create: `src/Services/WebsiteHttpChecker.php`
- Create: `tests/Fixtures/Websites/http-router.php`
- Create: `tests/Support/WebsiteHttpFixture.php`
- Test: `tests/Integration/Services/WebsiteHttpCheckerTest.php`
- Test: `tests/Unit/Services/WebsiteHttpRedactionTest.php`

**Interfaces:**
- Produces: `WebsiteHttpChecker::check(WebsiteEndpointDefinition $endpoint, int $websiteId, int $endpointId, bool $manual = false): WebsiteCheckResult`.
- Produces: `WebsiteHttpChecker::checkMany(list<array{definition:WebsiteEndpointDefinition,website_id:int,endpoint_id:int,manual:bool}>, int $concurrency): list<WebsiteCheckResult>`.
- Consumes: validator/assertion contracts from Task 1 and result DTO from Task 3.

- [ ] **Step 1: Create deterministic fixture routes and failing integration tests**

The fixture router exposes exact routes:

```php
return match ($path) {
    '/ok' => $respond(200, '<main id="health"><strong>OK</strong></main>'),
    '/redirect/start' => $redirect('/redirect/middle', 301),
    '/redirect/middle' => $redirect('/ok', 302),
    '/loop-a' => $redirect('/loop-b', 302),
    '/loop-b' => $redirect('/loop-a', 302),
    '/status/503' => $respond(503, 'temporarily unavailable'),
    '/large' => $streamBytes(1048577),
    '/slow' => $delayedResponse(250000, 200, 'slow'),
    '/headers' => $jsonResponse(getallheaders()),
    default => $respond(404, 'missing'),
};
```

Tests must assert: default redirect reaches `/ok`; redirect count is 2; disabled redirect evaluates 301; loop and limit categories differ; status 503 remains transport-available; missing text/selector are assertion failures; body over 1 MiB is `response_too_large`; timeout is distinct; DNS/connect/TLS/TTFB/total are non-negative; configured URL is retained; failed/manual results retain safe hop details.

- [ ] **Step 2: Add failing cross-origin credential tests**

```php
public function testSensitiveHeadersAreRemovedAcrossOriginByDefault(): void
{
    $target = $this->fixtures->secondOrigin('/headers');
    $start = $this->fixtures->firstOrigin('/redirect-to?target=' . rawurlencode($target));
    $result = $this->checker->check($this->definition($start, bearer: 'secret', headers: ['X-Api-Key'=>'hidden']), 1, 1);
    self::assertTrue($result->transportAvailable);
    self::assertFalse($result->diagnostics['received_authorization']);
    self::assertFalse($result->diagnostics['received_x_api_key']);
}
```

Add the positive case where the second hostname and port are explicitly present in `credentialRedirectHosts`; assert diagnostics contain booleans only, never values.

- [ ] **Step 3: Run tests and confirm the checker is missing**

Run: `vendor/bin/phpunit tests/Integration/Services/WebsiteHttpCheckerTest.php tests/Unit/Services/WebsiteHttpRedactionTest.php`

Expected: FAIL because fixture helper and checker do not exist.

- [ ] **Step 4: Implement manual redirect handling and bounded body reads**

Configure each cURL handle with `CURLOPT_FOLLOWLOCATION=false`, `CURLOPT_PROTOCOLS=CURLPROTO_HTTP|CURLPROTO_HTTPS`, `CURLOPT_CONNECTTIMEOUT_MS`, total remaining deadline, `CURLOPT_HEADERFUNCTION`, and a write callback that aborts after exactly 1 MiB. Resolve relative `Location` against the previous URL, normalize the next origin, detect repeated canonical URLs, and repeat at most 10 times.

Build outgoing headers anew for every hop. Send auth/custom sensitive headers only when the next origin equals the configured origin or appears in the explicit redirect-host list. Never pass secret values to a thrown exception or diagnostics array.

Derive per-hop milliseconds from cURL timing values:

```php
$dnsMs = 1000 * $info['namelookup_time'];
$tcpMs = 1000 * max(0.0, $info['connect_time'] - $info['namelookup_time']);
$tlsMs = 1000 * max(0.0, $info['appconnect_time'] - $info['connect_time']);
$hopTtfbMs = 1000 * $info['starttransfer_time'];
$hopTotalMs = 1000 * $info['total_time'];
```

Full total is the sum of hop totals; final TTFB is prior-hop totals plus final-hop TTFB. `checkMany()` uses `curl_multi_*`, admits at most the validated concurrency value, and advances each request through redirect rounds without exceeding its original deadline.

- [ ] **Step 5: Map cURL and assertion outcomes to finite safe categories**

Map DNS, connect/refused, timeout, TLS, redirect loop, redirect limit, redirect scheme, response too large, unexpected status, content missing, slow response and internal checker to `WebsiteCheckError`. Preserve transport availability for HTTP status/content/performance failures. Run assertions only after the final selected response and never store the response body.

- [ ] **Step 6: Run focused tests and PHPStan**

Run: `vendor/bin/phpunit tests/Integration/Services/WebsiteHttpCheckerTest.php tests/Unit/Services/WebsiteHttpRedactionTest.php && composer analyse`

Expected: PASS, fixture processes terminate in teardown, and no secret literal appears in failure output.

- [ ] **Step 7: Commit**

```bash
git add src/Services/WebsiteHttpChecker.php tests/Fixtures/Websites/http-router.php tests/Support/WebsiteHttpFixture.php tests/Integration/Services/WebsiteHttpCheckerTest.php tests/Unit/Services/WebsiteHttpRedactionTest.php
git commit -m "feat: probe website endpoints"
```

### Task 7: TLS certificate inspection and constrained self-signed mode

**Files:**
- Create: `src/Domain/Websites/TlsInspectionResult.php`
- Create: `src/Services/TlsCertificateInspector.php`
- Create: `src/Repositories/WebsiteTlsRepository.php`
- Modify: `src/Services/WebsiteHttpChecker.php`
- Create: `tests/Fixtures/Websites/tls-server.php`
- Create: `tests/Fixtures/Websites/certs/valid-self-signed.pem`
- Create: `tests/Fixtures/Websites/certs/wrong-host.pem`
- Create: `tests/Fixtures/Websites/certs/expired.pem`
- Test: `tests/Integration/Services/TlsCertificateInspectorTest.php`
- Test: `tests/Integration/Repositories/WebsiteTlsRepositoryTest.php`

**Interfaces:**
- Produces: `TlsCertificateInspector::inspect(?int $targetId, int $endpointId, string $hostname, int $port, bool $allowSelfSigned, DateTimeImmutable $now): TlsInspectionResult`.
- Produces: `WebsiteTlsRepository::syncTargets(int $endpointId, list<array{hostname:string,port:int,configured:bool}>): void`.
- Produces: `record(TlsInspectionResult $result): array{changed:bool,previous_fingerprint:?string}` and `dueTargets(DateTimeImmutable,int): list<array<string,mixed>>`.

- [ ] **Step 1: Add failing certificate-policy tests**

```php
public function testSelfSignedModeStillRejectsWrongHostname(): void
{
    $result = $this->inspector->inspect(1, 10, 'localhost', $this->wrongHostPort, true, $this->now);
    self::assertFalse($result->valid);
    self::assertSame('hostname_mismatch', $result->errorKind);
}

public function testSelfSignedModeStillRejectsExpiredCertificate(): void
{
    $result = $this->inspector->inspect(2, 10, 'localhost', $this->expiredPort, true, $this->now);
    self::assertFalse($result->valid);
    self::assertSame('certificate_expired', $result->errorKind);
}

public function testSelfSignedModeAcceptsOnlyUnknownCaForConfiguredHost(): void
{
    $strict = $this->inspector->inspect(3, 10, 'localhost', $this->selfSignedPort, false, $this->now);
    $allowed = $this->inspector->inspect(3, 10, 'localhost', $this->selfSignedPort, true, $this->now);
    self::assertSame('untrusted_chain', $strict->errorKind);
    self::assertTrue($allowed->valid);
}
```

Repository tests cover target deduplication by endpoint/hostname/port, configured plus final-redirect targets only, no propagation of self-signed permission to redirect hosts, daily due time, metadata, and certificate-change event without incident.

- [ ] **Step 2: Run focused tests and confirm missing TLS implementation**

Run: `vendor/bin/phpunit tests/Integration/Services/TlsCertificateInspectorTest.php tests/Integration/Repositories/WebsiteTlsRepositoryTest.php`

Expected: FAIL because TLS classes and fixtures do not exist; integration must not be skipped.

- [ ] **Step 3: Implement certificate capture and validation**

Use `stream_socket_client('tls://host:port', ...)` with peer certificate capture. Strict mode enables peer verification and peer-name verification. Self-signed mode disables peer-chain verification only, then explicitly validates SAN/CN hostname matching and `validFrom_time_t <= now < validTo_time_t`; protocol/handshake failure remains an error. Normalize wildcard SAN matching to one label only.

Modify `WebsiteHttpChecker` so a self-signed endpoint first passes this explicit inspection with `targetId=null`, then disables cURL peer-chain verification only for the configured hostname while keeping `CURLOPT_SSL_VERIFYHOST=2`. Redirect hops use strict cURL verification unless their own configured endpoint explicitly permits self-signed. Every HTTPS hop is transport-validated; only the configured and final redirect host are persisted as daily expiry targets.

Return only:

```php
new TlsInspectionResult(
    endpointId: $endpointId,
    hostname: $hostname,
    port: $port,
    checkedAt: $now,
    valid: $valid,
    errorKind: $errorKind,
    subject: $subject,
    issuer: $issuer,
    sans: $sans,
    fingerprintSha256: $fingerprint,
    notBefore: $notBefore,
    notAfter: $notAfter,
);
```

Do not store PEM bytes. `WebsiteTlsRepository` upserts current state, inserts a long-term certificate event only when fingerprint changes, and schedules the next check at `checked_at + 24 hours` with retry backoff for transport errors.

- [ ] **Step 4: Run focused tests and PHPStan**

Run: `vendor/bin/phpunit tests/Integration/Services/TlsCertificateInspectorTest.php tests/Integration/Repositories/WebsiteTlsRepositoryTest.php && composer analyse`

Expected: PASS for strict, self-signed, wrong-host, expired and certificate-change cases.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Websites/TlsInspectionResult.php src/Services/TlsCertificateInspector.php src/Repositories/WebsiteTlsRepository.php src/Services/WebsiteHttpChecker.php tests/Fixtures/Websites/tls-server.php tests/Fixtures/Websites/certs tests/Integration/Services/TlsCertificateInspectorTest.php tests/Integration/Repositories/WebsiteTlsRepositoryTest.php
git commit -m "feat: inspect website certificates"
```

### Task 8: RDAP-first domain-expiry monitoring with bounded WHOIS fallback

**Files:**
- Create: `src/Domain/Websites/DomainRegistrationResult.php`
- Create: `src/Services/RegistrationDomainNormalizer.php`
- Create: `src/Services/DomainRegistrationChecker.php`
- Create: `src/Repositories/DomainRegistrationRepository.php`
- Create: `resources/domain/iana-rdap-bootstrap.json`
- Create: `resources/domain/public_suffix_list.dat`
- Create: `resources/domain/whois-profiles.php`
- Create: `bin/refresh-domain-data`
- Create: `tests/Fixtures/Websites/rdap-router.php`
- Create: `tests/Fixtures/Websites/whois-server.php`
- Create: `tests/Fixtures/Websites/iana-rdap-bootstrap.json`
- Create: `tests/Fixtures/Websites/public_suffix_list.dat`
- Test: `tests/Unit/Services/RegistrationDomainNormalizerTest.php`
- Test: `tests/Integration/Services/DomainRegistrationCheckerTest.php`
- Test: `tests/Integration/Repositories/DomainRegistrationRepositoryTest.php`
- Test: `tests/Contract/DomainDataSnapshotContractTest.php`

**Interfaces:**
- Produces: `RegistrationDomainNormalizer::normalize(string): string` returning registrable ASCII/Punycode domain.
- Produces: `DomainRegistrationChecker::check(string $domain, DateTimeImmutable $now): DomainRegistrationResult`.
- Produces: `DomainRegistrationRepository::record(int $websiteId, DomainRegistrationResult): void`, `dueWebsites(DateTimeImmutable,int): list<array<string,mixed>>`.

- [ ] **Step 1: Add failing normalization and source-selection tests**

```php
#[DataProvider('domains')]
public function testNormalizesOnlyRegistrableDomains(string $input, string $expected): void
{
    self::assertSame($expected, $this->normalizer->normalize($input));
}

public static function domains(): iterable
{
    yield ['пример.рф', 'xn--e1afmkfd.xn--p1ai'];
    yield ['example.co.uk', 'example.co.uk'];
    yield ['corp.local', 'corp.local'];
}
```

Add rejection cases for scheme/path/IP/public subdomain/single public-suffix label, and unsupported non-incident cases for `.local` and internal zones. Integration tests assert RDAP first, first matching expiration event, registrar extraction, HTTP 429 `Retry-After`, malformed response as source-unknown, WHOIS fallback only for a profiled zone, and no raw source response persisted.

- [ ] **Step 2: Run focused tests and confirm missing implementation/resources**

Run: `vendor/bin/phpunit tests/Unit/Services/RegistrationDomainNormalizerTest.php tests/Integration/Services/DomainRegistrationCheckerTest.php tests/Integration/Repositories/DomainRegistrationRepositoryTest.php tests/Contract/DomainDataSnapshotContractTest.php`

Expected: FAIL because services, repository and snapshots do not exist.

- [ ] **Step 3: Add pinned official snapshots and atomic refresh command**

Bundle the current official IANA `dns.json` RDAP bootstrap and Public Suffix List with source URL, retrieval date and SHA-256 metadata. `bin/refresh-domain-data` downloads to files created with `tempnam()` under `resources/domain`, validates JSON/PSL structure, fsyncs, then atomically renames. On any failure it leaves the existing snapshots untouched and exits non-zero without logging downloaded content.

- [ ] **Step 4: Implement normalizer and RDAP/WHOIS checker**

Use `idn_to_ascii(..., IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)`, then the bundled PSL to compute the registrable domain. Reject a public input when it differs from its registrable domain; retain a syntactically valid internal two-label name so the checker can return `unsupported`. Select the RDAP base URL from the IANA bootstrap, request `/domain/{domain}` with the same 15-second/1-MiB bounds, and parse `events` where action is `expiration`; registrar is optional.

WHOIS fallback uses `stream_socket_client()` with a 10-second deadline, sends exactly the ASCII domain plus CRLF, reads at most 1 MiB, and invokes only a versioned per-zone parser profile. The initial profiles cover Verisign `Registry Expiry Date`, generic `Expiration Date`/`Expiry Date`, and RU/РФ `paid-till`; each profile explicitly maps its zones and WHOIS server. It follows at most one referral. Persist category, expiration, registrar, source and checked time only.

- [ ] **Step 5: Implement daily cache/backoff persistence**

Successful/supported/unsupported results schedule `+24 hours`. Rate limits honor a safe `Retry-After` capped at 24 hours. Transport/parser unknown uses exponential retry capped at six hours and never opens an expiry incident. Expiry warning/critical is derived only after a successful metadata result.

- [ ] **Step 6: Run focused tests and validate the PHP command**

Run: `vendor/bin/phpunit tests/Unit/Services/RegistrationDomainNormalizerTest.php tests/Integration/Services/DomainRegistrationCheckerTest.php tests/Integration/Repositories/DomainRegistrationRepositoryTest.php tests/Contract/DomainDataSnapshotContractTest.php && php -l bin/refresh-domain-data`

Expected: PASS with fixture URLs only during tests and no external network dependency.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Websites/DomainRegistrationResult.php src/Services/RegistrationDomainNormalizer.php src/Services/DomainRegistrationChecker.php src/Repositories/DomainRegistrationRepository.php resources/domain bin/refresh-domain-data tests/Fixtures/Websites tests/Unit/Services/RegistrationDomainNormalizerTest.php tests/Integration/Services/DomainRegistrationCheckerTest.php tests/Integration/Repositories/DomainRegistrationRepositoryTest.php tests/Contract/DomainDataSnapshotContractTest.php
git commit -m "feat: monitor domain registration expiry"
```

### Task 9: Generalized notification recipients and website maintenance

**Files:**
- Modify: `src/Repositories/NotificationOutboxRepository.php`
- Modify: `src/Repositories/MaintenanceWindowRepository.php`
- Modify: `src/Notifications/NotificationMessageFormatter.php`
- Modify: `tests/Integration/Repositories/NotificationSettingsRepositoryTest.php`
- Modify: `tests/Integration/Repositories/MaintenanceWindowRepositoryTest.php`
- Modify: `tests/Unit/Notifications/NotificationMessageFormatterTest.php`
- Test: `tests/Integration/Repositories/WebsiteNotificationOutboxTest.php`

**Interfaces:**
- Preserves: existing `enqueueConfigured(int $serverId, int $alertId, string $eventType, array $payload, string $deduplicationKey): int` behavior.
- Produces: `enqueueWebsiteConfigured(int $websiteId, int $alertId, string $eventType, array $payload, string $deduplicationKey): int`.
- Produces: `startWebsite(int $websiteId, int $durationSeconds, ?string $reason, ?string $createdBy): void`, `cancelWebsite(int): int`, `activeWebsite(int): ?array`.

- [ ] **Step 1: Add failing website recipient, maintenance, and formatter tests**

```php
public function testWebsiteRecipientsOverrideGlobalRecipients(): void
{
    $count = $this->outbox->enqueueWebsiteConfigured(
        $this->websiteId,
        $this->alertId,
        'website_http_triggered',
        ['type'=>'website_http','severity'=>'critical','website_name'=>'Portal','endpoint_name'=>'Home','safe_url'=>'https://example.com/'],
        'website:' . $this->websiteId . ':alert:' . $this->alertId . ':triggered'
    );
    self::assertSame(2, $count);
    self::assertSame($this->expectedRecipients, $this->storedRecipients());
}
```

Cover global fallback, severity flags, cooldown scoped by website/event, website maintenance suppressing delivery but not alert creation, server behavior unchanged, sanitized URL, incident link, and formatter output containing no body/header/token/password values.

- [ ] **Step 2: Run focused tests and confirm missing website methods**

Run: `vendor/bin/phpunit tests/Integration/Repositories/WebsiteNotificationOutboxTest.php tests/Integration/Repositories/NotificationSettingsRepositoryTest.php tests/Integration/Repositories/MaintenanceWindowRepositoryTest.php tests/Unit/Notifications/NotificationMessageFormatterTest.php`

Expected: FAIL on missing website methods while current server tests remain green.

- [ ] **Step 3: Implement website-source paths without changing server call sites**

Keep `enqueueConfigured()` as a server wrapper and share a private method:

```php
private function enqueueForSource(
    ?int $serverId,
    ?int $websiteId,
    int $alertId,
    string $eventType,
    array $payload,
    string $deduplicationKey
): int
```

Recipient lookup uses `websites.notification_*` for website events. Cooldown and maintenance predicates choose the non-null source. Payload validation accepts a fixed website key set and recursively rejects keys matching `body|header|authorization|token|password|secret|credential` case-insensitively.

Add website-specific maintenance methods sharing one source-aware internal implementation; keep existing public server methods/signatures intact.

- [ ] **Step 4: Extend notification formatting with website events**

Format trigger/recovery messages for HTTP availability, assertion, performance, TLS and domain. Include website, endpoint when present, expected/actual safe values, effective incident time and `/sites/{id}?tab=events#incident-{alert_id}` link. Render URLs only through `WebsiteUrlSanitizer`.

- [ ] **Step 5: Run focused tests**

Run: `vendor/bin/phpunit tests/Integration/Repositories/WebsiteNotificationOutboxTest.php tests/Integration/Repositories/NotificationSettingsRepositoryTest.php tests/Integration/Repositories/MaintenanceWindowRepositoryTest.php tests/Unit/Notifications/NotificationMessageFormatterTest.php`

Expected: PASS for website and existing server cases.

- [ ] **Step 6: Commit**

```bash
git add src/Repositories/NotificationOutboxRepository.php src/Repositories/MaintenanceWindowRepository.php src/Notifications/NotificationMessageFormatter.php tests/Integration/Repositories/WebsiteNotificationOutboxTest.php tests/Integration/Repositories/NotificationSettingsRepositoryTest.php tests/Integration/Repositories/MaintenanceWindowRepositoryTest.php tests/Unit/Notifications/NotificationMessageFormatterTest.php
git commit -m "feat: notify on website incidents"
```

### Task 10: Confirmed website state transitions and unified incidents

**Files:**
- Create: `src/Domain/Websites/WebsiteStateDecision.php`
- Create: `src/Services/WebsiteStateEvaluator.php`
- Create: `src/Services/WebsiteIncidentService.php`
- Create: `src/Repositories/WebsiteAvailabilityRepository.php`
- Modify: `src/Repositories/IncidentRepository.php`
- Test: `tests/Unit/Services/WebsiteStateEvaluatorTest.php`
- Test: `tests/Integration/Services/WebsiteIncidentServiceTest.php`
- Modify: `tests/Integration/Repositories/IncidentRepositoryTest.php`

**Interfaces:**
- Produces: `WebsiteStateEvaluator::evaluate(array $current, WebsiteCheckResult $result): WebsiteStateDecision`.
- Produces: `WebsiteIncidentService::recordHttp(WebsiteCheckResult): void`, `recordTls(TlsInspectionResult): void`, `recordDomain(int, DomainRegistrationResult): void`, `pause(int, DateTimeImmutable): void`.
- Produces: `WebsiteAvailabilityRepository::timeline(int $websiteId, ?int $endpointId, DateTimeImmutable $from, DateTimeImmutable $to): list<array<string,mixed>>`.
- Extends: `IncidentRepository` filters/read models with `website_id`, `endpoint_id`, and `source_type` while preserving server filters.

- [ ] **Step 1: Add failing pure state-machine tests**

```php
public function testThirdFailureOpensAtFirstFailureAndSecondSuccessClosesAtFirstSuccess(): void
{
    $state = $this->noDataState();
    $state = $this->evaluator->evaluate($state, $this->failure('00:00:00'))->nextState;
    self::assertSame('possible_problem', $state['transport_state']);
    $state = $this->evaluator->evaluate($state, $this->failure('00:01:00'))->nextState;
    $opened = $this->evaluator->evaluate($state, $this->failure('00:02:00'));
    self::assertTrue($opened->openIncident);
    self::assertSame('2026-08-27T00:00:00+00:00', $opened->effectiveAt->format(DATE_ATOM));

    $recovering = $this->evaluator->evaluate($opened->nextState, $this->success('00:03:00'));
    self::assertFalse($recovering->closeIncident);
    $closed = $this->evaluator->evaluate($recovering->nextState, $this->success('00:04:00'));
    self::assertTrue($closed->closeIncident);
    self::assertSame('2026-08-27T00:03:00+00:00', $closed->effectiveAt->format(DATE_ATOM));
}
```

Add independent tests for transport/assertion/performance counters, error-kind changes during one outage, one manual success not closing an incident, primary/additional aggregate labels, no-data, paused and resume semantics.

- [ ] **Step 2: Add failing transactional incident tests**

Cover one active alert per website/endpoint/kind, independent simultaneous kinds, availability events linked to alert IDs, notification only on transition, effective timestamps, proactive TLS/domain warning versus critical, source-unknown domain producing no alert, and pause closing every active website alert with reason `monitoring_paused` without a recovery notification.

- [ ] **Step 3: Run tests and confirm missing state services**

Run: `vendor/bin/phpunit tests/Unit/Services/WebsiteStateEvaluatorTest.php tests/Integration/Services/WebsiteIncidentServiceTest.php tests/Integration/Repositories/IncidentRepositoryTest.php`

Expected: FAIL because state/incident classes and website incident queries do not exist.

- [ ] **Step 4: Implement pure dimension decisions**

Evaluate transport, assertions and performance independently. A result can be transport healthy, assertion failed and performance critical at once. Store first-failure/first-success timestamps per dimension. Return a decision object containing next state, open/close flags, alert kind/severity, effective time and safe diagnostic.

TLS/domain expiry state changes immediately after a successful metadata check because their daily source is authoritative; transient source failures remain diagnostic. An active TLS handshake/trust/hostname/time error from an HTTP/TLS probe uses kind `website_tls` rather than opening a duplicate generic HTTP incident. Its sample remains transport-unavailable, but the aggregate site label is TLS critical rather than generic «Недоступен». Proactive TLS-expiry and all domain states never change transport availability.

- [ ] **Step 5: Implement one transactional incident application service**

For each result, lock endpoint/site state, evaluate dimensions, insert/update/resolve alerts, insert availability transitions, enqueue transition notifications, recompute site aggregate, record sample/current diagnostics, then commit. Network work must never occur in this transaction. Update an active alert's subject/diagnostic when error kind changes instead of opening another alert.

Generalize `IncidentRepository` with `LEFT JOIN` server/website source models and return a common shape:

```php
[
    'id' => 42,
    'source_type' => 'website',
    'server_id' => null,
    'website_id' => 7,
    'endpoint_id' => 9,
    'source_name' => 'Portal',
    'endpoint_name' => 'Home',
    'group_name' => 'Production',
    'kind' => 'website_http',
    'severity' => 'critical',
]
```

- [ ] **Step 6: Run focused tests and PHPStan**

Run: `vendor/bin/phpunit tests/Unit/Services/WebsiteStateEvaluatorTest.php tests/Integration/Services/WebsiteIncidentServiceTest.php tests/Integration/Repositories/IncidentRepositoryTest.php && composer analyse`

Expected: PASS with exact 3/2 timestamps and unchanged server incident behavior.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Websites/WebsiteStateDecision.php src/Services/WebsiteStateEvaluator.php src/Services/WebsiteIncidentService.php src/Repositories/WebsiteAvailabilityRepository.php src/Repositories/IncidentRepository.php tests/Unit/Services/WebsiteStateEvaluatorTest.php tests/Integration/Services/WebsiteIncidentServiceTest.php tests/Integration/Repositories/IncidentRepositoryTest.php
git commit -m "feat: manage website incidents"
```

### Task 11: Supervised website worker and health integration

**Files:**
- Create: `src/Workers/WebsiteCheckWorker.php`
- Create: `src/Services/WebsiteProbeExecutor.php`
- Create: `src/Services/CentralWebsiteProbeExecutor.php`
- Create: `bin/website-check-worker`
- Modify: `src/Repositories/WorkerHeartbeatRepository.php`
- Modify: `src/Services/SystemHealthService.php`
- Modify: `docker/supervisord.conf`
- Modify: `docker/Dockerfile`
- Modify: `docker/entrypoint.sh`
- Modify: `tests/Contract/WorkerRuntimeContractTest.php`
- Modify: `tests/Contract/ComposeContractTest.php`
- Test: `tests/Integration/Workers/WebsiteCheckWorkerTest.php`
- Modify: `tests/Integration/Services/SystemHealthServiceTest.php`

**Interfaces:**
- Produces: `WebsiteCheckWorker::runOnce(?DateTimeImmutable $now = null): int`.
- Produces: `WebsiteProbeExecutor::execute(list<array<string,mixed>> $jobs, int $concurrency): list<array{job_id:int,result:WebsiteCheckResult|TlsInspectionResult|DomainRegistrationResult}>`.
- Consumes: queue, website config, HTTP/TLS/domain checkers, metrics, TLS/domain repositories and incident service from Tasks 3–10.
- Preserves: supervisor restart, PDO rebuild, heartbeat and exactly two Compose services.

- [ ] **Step 1: Add failing orchestration and runtime contract tests**

```php
public function testWorkerPerformsNetworkOutsideDatabaseTransaction(): void
{
    $probe = new RecordingProbeExecutor(static fn (): bool => self::$pdo?->inTransaction() ?? true);
    $worker = $this->worker($probe);
    self::assertSame(1, $worker->runOnce($this->now));
    self::assertFalse($probe->transactionWasOpen);
    self::assertSame(1, $this->sampleCount());
}
```

Cover scheduled/manual HTTP, TLS and domain jobs; bounded batch size; result transaction rollback; lease release/backoff on known source failures; unknown exception propagation; stopped site ignored; one manual success not closing an incident; heartbeat name and stale health behavior.

Extend `WorkerRuntimeContractTest` so `bin/website-check-worker` must discard its PDO object graph on `PDOException`, exit on unknown `Throwable`, honor SIGTERM, and never print exception messages. Extend Compose contract to keep only `app` and `db`.

- [ ] **Step 2: Run focused tests and confirm missing worker/runtime entries**

Run: `vendor/bin/phpunit tests/Integration/Workers/WebsiteCheckWorkerTest.php tests/Integration/Services/SystemHealthServiceTest.php tests/Contract/WorkerRuntimeContractTest.php tests/Contract/ComposeContractTest.php`

Expected: FAIL because worker, heartbeat constant and supervisor program do not exist.

- [ ] **Step 3: Implement bounded orchestration**

`runOnce()` must:

```php
public function runOnce(?DateTimeImmutable $now = null): int
{
    $now ??= new DateTimeImmutable();
    $this->queue->scheduleDue($now);
    $jobs = $this->queue->claim($this->leaseOwner, $now, $this->concurrency);
    $results = $this->probes->execute($jobs, $this->concurrency);
    foreach ($results as $completed) {
        $this->applyCompletedJob($completed, $now);
    }
    return count($jobs);
}
```

The sketch defines the control flow; `CentralWebsiteProbeExecutor` dispatches HTTP/TLS/domain network work with no open transaction. `applyCompletedJob()` opens a fresh transaction only for persistence/state/outbox/completion. After an HTTP result it synchronizes daily TLS targets for the configured host and a distinct final HTTPS redirect host; intermediate HTTPS hops are validated during the request but are not retained as expiry targets. HTTP jobs are concurrent up to `1..50`; TLS/domain work uses a maximum of five simultaneous sockets/requests. Every claimed job is completed or safely released.

- [ ] **Step 4: Add resilient CLI runtime and supervisor program**

`bin/website-check-worker` follows `bin/offline-worker`: validate `WEBSITE_CHECK_LOOP_INTERVAL` in `1..60`, support `--once`, handle SIGTERM/SIGINT, rebuild all PDO-bound objects after `PDOException`, emit only stable category strings, and exit 1 for unknown failures. Add `WEBSITE_CHECK_WORKER` to `WorkerHeartbeatRepository` and health UI.

Add one `[program:website-check-worker]` to supervisor at priority 25. Add executable/chown/platform checks in Dockerfile/entrypoint. Do not add a Compose service.

- [ ] **Step 5: Run focused tests and one-shot worker against fixtures**

Run: `vendor/bin/phpunit tests/Integration/Workers/WebsiteCheckWorkerTest.php tests/Integration/Services/SystemHealthServiceTest.php tests/Contract/WorkerRuntimeContractTest.php tests/Contract/ComposeContractTest.php && bin/website-check-worker --once`

Expected: PASS; one-shot exits 0, writes heartbeat and processes the seeded local-fixture job.

- [ ] **Step 6: Commit**

```bash
git add src/Workers/WebsiteCheckWorker.php src/Services/WebsiteProbeExecutor.php src/Services/CentralWebsiteProbeExecutor.php bin/website-check-worker src/Repositories/WorkerHeartbeatRepository.php src/Services/SystemHealthService.php docker/supervisord.conf docker/Dockerfile docker/entrypoint.sh tests/Contract/WorkerRuntimeContractTest.php tests/Contract/ComposeContractTest.php tests/Integration/Workers/WebsiteCheckWorkerTest.php tests/Integration/Services/SystemHealthServiceTest.php
git commit -m "feat: run centralized website checks"
```

### Task 12: Admin-only website CRUD, forms, routes, and audit

**Files:**
- Create: `src/Controllers/WebsiteController.php`
- Create: `templates/sites/index.twig`
- Create: `templates/sites/form.twig`
- Create: `templates/sites/create.twig`
- Create: `templates/sites/edit.twig`
- Create: `public/js/sites-form.js`
- Create: `translations/ru.websites.php`
- Create: `translations/en.websites.php`
- Modify: `src/Application/Bootstrap.php`
- Modify: `src/Application/AppFactory.php`
- Modify: `src/Middlewares/AuditTrailMiddleware.php`
- Modify: `templates/layout.twig`
- Modify: `public/css/ui19.css`
- Modify: `public/css/ui19-review.css`
- Modify: `tests/Contract/RouteSecurityContractTest.php`
- Modify: `tests/Unit/Contracts/LocalizationContractTest.php`
- Test: `tests/Integration/Controllers/WebsiteControllerTest.php`
- Test: `tests/Integration/Middleware/WebsiteAuditTrailMiddlewareTest.php`
- Create: `tests/Contract/WebsiteMonitoringContractTest.php`

**Interfaces:**
- Produces routes: read-only `GET /sites`; admin `GET /sites/create`, `POST /sites`, `GET /sites/{id}/edit`, `POST /sites/{id}`, `POST /sites/{id}/delete`, `POST /sites/{id}/pause`, `POST /sites/{id}/resume`, `POST /sites/{id}/check`.
- Consumes: validator and repository; controller never performs network I/O.
- Produces audit actions: `website.create`, `website.update`, `website.delete`, `website.pause`, `website.resume`, `website.check.request`.

- [ ] **Step 1: Add failing route and authorization contracts**

```php
public function testWebsiteMutationRoutesArePostAdminCsrfOnly(): void
{
    foreach (['/sites','/sites/{id}','/sites/{id}/delete','/sites/{id}/pause',
        '/sites/{id}/resume','/sites/{id}/check'] as $pattern) {
        self::assertSame(['POST'], $this->methods($pattern));
        self::assertTrue($this->hasAdminMiddleware($pattern));
    }
}
```

Controller tests assert authenticated users can list but receive 403 for every create/edit/mutation route through the real Slim app; admins can create a site with multiple endpoints, validation errors preserve non-secret fields, edit renders `has_auth_secret`/`has_headers` flags but no values, manual check only enqueues, delete cascades website data, and all redirects are stable.

- [ ] **Step 2: Add failing audit redaction tests**

Submit Basic/Bearer/header values and self-signed changes through the real app. Assert audit contains changed field names, endpoint IDs/count, URLs sanitized without userinfo, and booleans for secret presence; recursively assert JSON metadata contains none of the submitted secret literals.

- [ ] **Step 3: Run focused tests and confirm route/controller failures**

Run: `vendor/bin/phpunit tests/Integration/Controllers/WebsiteControllerTest.php tests/Integration/Middleware/WebsiteAuditTrailMiddlewareTest.php tests/Contract/RouteSecurityContractTest.php tests/Contract/WebsiteMonitoringContractTest.php tests/Unit/Contracts/LocalizationContractTest.php`

Expected: FAIL because routes, controller, templates and translations do not exist.

- [ ] **Step 4: Register services and routes with explicit middleware**

Register `WebsiteRepository`, validator, queue and `WebsiteController` in `Bootstrap`. Add read-only list under the protected group. Add admin middleware to create/edit GET routes and every POST website route; the parent protected group still supplies auth, CSRF and audit middleware. Do not expose any website route outside authenticated groups.

- [ ] **Step 5: Implement list and separate create/edit forms**

The list groups sites by `monitoring_groups`, supports query/search/status filters, and renders status, primary URL, latest total time, availability, nearest TLS/domain deadline and last check.

The form manages site identity plus repeatable endpoint cards with name, primary radio, URL, method, interval, redirect, optional status ranges, page text/CSS checks, timeout/speed thresholds, Basic/Bearer fields, allowed custom headers, credential redirect hosts, self-signed warning and TLS expiry. JavaScript only adds/removes/reindexes form rows; all validation remains server-side. A primary endpoint must be selected before submit.

- [ ] **Step 6: Extend audit snapshots with secret fingerprints only**

`AuditTrailMiddleware` reads persisted website state before/after, replacing encrypted bytes with `hash('sha256', bytes)` solely to detect change. Metadata uses aliases `auth_secret`, `custom_headers`, `self_signed`, `endpoints`, and never includes encrypted or decrypted bytes. Manual check audit records only website ID/name and endpoint count.

- [ ] **Step 7: Add navigation, responsive CSS, and complete ru/en catalogs**

Add `/sites` between Servers and Incidents in `layout.twig`. Add the new templates to `LocalizationContractTest`. Use a dedicated translation fragment with identical sorted keys for both locales and exact Russian copy «Что мониторится». Keep all icons/fonts/scripts self-hosted.

- [ ] **Step 8: Run focused tests and frontend contracts**

Run: `vendor/bin/phpunit tests/Integration/Controllers/WebsiteControllerTest.php tests/Integration/Middleware/WebsiteAuditTrailMiddlewareTest.php tests/Contract/RouteSecurityContractTest.php tests/Contract/WebsiteMonitoringContractTest.php tests/Unit/Contracts/LocalizationContractTest.php tests/Contract/FrontendAssetContractTest.php tests/Contract/TemplateSecurityContractTest.php`

Expected: PASS with no secret literal in HTML, response headers or audit rows.

- [ ] **Step 9: Commit**

```bash
git add src/Controllers/WebsiteController.php src/Application/Bootstrap.php src/Application/AppFactory.php src/Middlewares/AuditTrailMiddleware.php templates/sites templates/layout.twig public/js/sites-form.js public/css/ui19.css public/css/ui19-review.css translations/ru.websites.php translations/en.websites.php tests/Integration/Controllers/WebsiteControllerTest.php tests/Integration/Middleware/WebsiteAuditTrailMiddlewareTest.php tests/Contract tests/Unit/Contracts/LocalizationContractTest.php
git commit -m "feat: manage websites in the UI"
```

### Task 13: Site detail overview, metrics, events, settings, and chart links

**Files:**
- Create: `src/Controllers/WebsiteDetailController.php`
- Create: `src/Controllers/Api/WebsiteMetricsApiController.php`
- Create: `templates/sites/detail.twig`
- Create: `templates/sites/partials/detail-overview.twig`
- Create: `templates/sites/partials/detail-metrics.twig`
- Create: `templates/sites/partials/detail-events.twig`
- Create: `templates/sites/partials/detail-settings.twig`
- Create: `public/js/site-detail.js`
- Modify: `src/Application/Bootstrap.php`
- Modify: `src/Application/AppFactory.php`
- Modify: `public/css/ui19-review.css`
- Modify: `translations/ru.websites.php`
- Modify: `translations/en.websites.php`
- Test: `tests/Integration/Controllers/WebsiteDetailControllerTest.php`
- Test: `tests/Integration/Controllers/WebsiteMetricsApiControllerTest.php`
- Test: `tests/Unit/Templates/WebsiteDetailTemplateTest.php`

**Interfaces:**
- Produces: `GET /sites/{id}?tab=overview|metrics|events|settings`.
- Produces: `GET /api/sites/{id}/metrics?endpoint_id=&period=&from=&to=` and `GET /api/sites/{id}/status`.
- Produces admin actions: `POST /sites/{id}/settings`, `/maintenance`, `/maintenance/cancel`.

- [ ] **Step 1: Add failing tab and JSON contract tests**

```php
#[DataProvider('tabs')]
public function testDetailTabsRender(string $tab, string $marker): void
{
    $response = $this->controller->show($this->request('/sites/7?tab=' . $tab), $this->response(), ['id'=>'7']);
    self::assertSame(200, $response->getStatusCode());
    self::assertStringContainsString($marker, (string) $response->getBody());
}

public static function tabs(): iterable
{
    yield ['overview', 'Что мониторится'];
    yield ['metrics', 'data-website-metrics-chart'];
    yield ['events', 'incident-timeline'];
    yield ['settings', 'website-threshold-settings'];
}
```

API tests assert endpoint ownership, allowed periods `1h/6h/24h/7d/30d/365d/custom`, raw/hourly/daily source metadata, separate availability/assertion ratios, TTFB/total series only, availability intervals with `alert_id` links, no diagnostics secrets, and 404/422 JSON errors without SQL details.

- [ ] **Step 2: Run focused tests and confirm missing detail/API implementation**

Run: `vendor/bin/phpunit tests/Integration/Controllers/WebsiteDetailControllerTest.php tests/Integration/Controllers/WebsiteMetricsApiControllerTest.php tests/Unit/Templates/WebsiteDetailTemplateTest.php`

Expected: FAIL because controllers/templates/routes do not exist.

- [ ] **Step 3: Implement detail read model and four tabs**

Overview renders configured checks, reasons/expectations, interval, primary/final URL, redirect count, auth/header presence only, self-signed warning, TLS/domain state, latest timings and active problems. Metrics renders endpoint selector with “all site”, primary and additional endpoints plus period controls. Events uses unified incident rows filtered by website/endpoint/kind. Settings contains only thresholds, notification policy and maintenance; identity/endpoints remain on the separate edit page.

- [ ] **Step 4: Implement safe metrics/status JSON**

Return JSON shaped as:

```json
{
  "source": "raw",
  "bucket_seconds": 60,
  "series": {
    "transport_availability": [],
    "assertion_success": [],
    "ttfb_ms": [],
    "total_ms": []
  },
  "incidents": [{"id":42,"kind":"website_http","start":"...","end":null}],
  "availability_intervals": [{"state":"unavailable","start":"...","end":null,"alert_id":42}]
}
```

Use ISO-8601 timestamps and numeric/null values only. Do not return configured headers, credentials, response body, certificate PEM, raw RDAP/WHOIS or internal exceptions.

- [ ] **Step 5: Implement Chart.js rendering and clickable incident markers**

Create only availability/assertion, TTFB and total charts. Clicking an outage interval or incident marker navigates to `/sites/{id}?tab=events#incident-{alert_id}`. Preserve zoom/reset conventions from server detail, destroy charts before re-render, and handle no-data without console errors.

- [ ] **Step 6: Implement admin settings and website maintenance actions**

Validate response/TLS/domain thresholds with the same ranges as repository defaults. Settings and maintenance routes carry explicit AdminMiddleware, CSRF and audit snapshots. Maintenance keeps checks/incidents running and suppresses only delivery.

- [ ] **Step 7: Run focused tests, translation and template contracts**

Run: `vendor/bin/phpunit tests/Integration/Controllers/WebsiteDetailControllerTest.php tests/Integration/Controllers/WebsiteMetricsApiControllerTest.php tests/Unit/Templates/WebsiteDetailTemplateTest.php tests/Unit/Contracts/LocalizationContractTest.php tests/Contract/TemplateSecurityContractTest.php`

Expected: PASS with no secret-bearing fields in either HTML or JSON.

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/WebsiteDetailController.php src/Controllers/Api/WebsiteMetricsApiController.php src/Application/Bootstrap.php src/Application/AppFactory.php templates/sites/detail.twig templates/sites/partials public/js/site-detail.js public/css/ui19-review.css translations/ru.websites.php translations/en.websites.php tests/Integration/Controllers/WebsiteDetailControllerTest.php tests/Integration/Controllers/WebsiteMetricsApiControllerTest.php tests/Unit/Templates/WebsiteDetailTemplateTest.php
git commit -m "feat: show website monitoring details"
```

### Task 14: Dashboard, shared groups, and unified incident UI

**Files:**
- Modify: `src/Controllers/DashboardController.php`
- Modify: `src/Controllers/GroupController.php`
- Modify: `src/Controllers/AlertController.php`
- Modify: `src/Repositories/IncidentRepository.php`
- Modify: `src/Repositories/WebsiteRepository.php`
- Modify: `templates/dashboard.twig`
- Modify: `templates/groups/index.twig`
- Modify: `templates/groups/show.twig`
- Modify: `templates/alerts/index.twig`
- Modify: `public/js/dashboard.js`
- Modify: `public/js/ui19.js`
- Modify: `public/css/ui19.css`
- Modify: `public/css/ui19-review.css`
- Modify: `translations/ru.websites.php`
- Modify: `translations/en.websites.php`
- Modify: `tests/Integration/Controllers/DashboardReadModelTest.php`
- Modify: `tests/Integration/Controllers/GroupControllerTest.php`
- Modify: `tests/Integration/Controllers/AlertControllerTest.php`
- Modify: `tests/Contract/IncidentUiContractTest.php`
- Modify: `tests/Contract/Ui19ContractTest.php`

**Interfaces:**
- Extends dashboard data with `website_stats`, `website_attention`, and website live status without changing existing server keys.
- Extends group read models with separate `servers` and `websites` arrays plus a shared problem summary.
- Extends incident filters with `source_type`, `website_id`, `endpoint_id`, and safe source links.

- [ ] **Step 1: Add failing dashboard layout/read-model tests**

```php
public function testDashboardOrdersServerAndWebsiteContainersBeforeAttention(): void
{
    $html = $this->renderDashboard();
    $servers = strpos($html, 'data-summary-section="servers"');
    $sites = strpos($html, 'data-summary-section="websites"');
    $attention = strpos($html, 'data-attention-section');
    self::assertIsInt($servers);
    self::assertGreaterThan($servers, $sites);
    self::assertGreaterThan($sites, $attention);
}
```

Assert matching container classes, server cards still below attention, site problems link to site events, first-failure possible state is visible, summary counts use current state rather than raw sample scans, and dashboard query count stays bounded.

- [ ] **Step 2: Add failing shared group and incident tests**

Create server+site, site-only and server-only groups. Assert index/show display separate blocks and combined active-problem count. Incident tests cover active/history filters, website endpoint labels, server regression, direct detail links and responsive labels.

- [ ] **Step 3: Run focused tests and confirm current UI lacks website models**

Run: `vendor/bin/phpunit tests/Integration/Controllers/DashboardReadModelTest.php tests/Integration/Controllers/GroupControllerTest.php tests/Integration/Controllers/AlertControllerTest.php tests/Contract/IncidentUiContractTest.php tests/Contract/Ui19ContractTest.php`

Expected: FAIL on website summary/group/incident assertions.

- [ ] **Step 4: Implement set-based summary/read models**

Use `website_state` and latest endpoint-state rows; never join raw `website_check_samples` on dashboard/group pages. Return one summary query and one grouped-site query, with aggregate statuses `healthy`, `unavailable`, `problem`, `degraded`, `slow`, `warning`, `critical`, `no_data`, `paused`, and possible-problem progress. Merge website/server attention in `IncidentRepository::attention()` with stable severity/time ordering and a hard limit.

- [ ] **Step 5: Implement the approved dashboard and group structure**

Render system health, then a full-width “Servers” container, an identically styled “Sites” container, then unified “Needs attention”, then existing server toolbar/groups/cards. The site container contains counts and actions, not the full site catalog. Group pages use shared group identity with separate server/site blocks and permit site-only groups.

- [ ] **Step 6: Extend incident list and dashboard polling safely**

Filters and links choose source type. Existing server DOM IDs/API keys remain unchanged. Website polling updates only summary counts/current labels and does not fetch historical samples. `ui19.js` responsive table labels include the new website columns.

- [ ] **Step 7: Run focused tests and PHPStan**

Run: `vendor/bin/phpunit tests/Integration/Controllers/DashboardReadModelTest.php tests/Integration/Controllers/GroupControllerTest.php tests/Integration/Controllers/AlertControllerTest.php tests/Contract/IncidentUiContractTest.php tests/Contract/Ui19ContractTest.php && composer analyse`

Expected: PASS with bounded query counts and all existing server assertions unchanged.

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/DashboardController.php src/Controllers/GroupController.php src/Controllers/AlertController.php src/Repositories/IncidentRepository.php src/Repositories/WebsiteRepository.php templates/dashboard.twig templates/groups templates/alerts/index.twig public/js/dashboard.js public/js/ui19.js public/css/ui19.css public/css/ui19-review.css translations/ru.websites.php translations/en.websites.php tests/Integration/Controllers tests/Contract/IncidentUiContractTest.php tests/Contract/Ui19ContractTest.php
git commit -m "feat: integrate websites into monitoring views"
```

### Task 15: Benchmarks, documentation, browser evidence, and release gates

**Files:**
- Create: `bin/benchmark-websites`
- Modify: `README.md`
- Modify: `ARCHITECTURE.md`
- Modify: `TECHNICAL_SPECIFICATION.md`
- Modify: `INSTALL.md`
- Modify: `.env.example`
- Modify: `docker/.env.example`
- Modify: `docker/README.md`
- Modify: `docs/use-cases.md`
- Modify: `docs/troubleshooting.md`
- Modify: `tests/Contract/DocumentationContractTest.php`
- Modify: `tests/Contract/ComposeContractTest.php`

**Interfaces:**
- Produces: reproducible 50/1000-site benchmark output with query count and PostgreSQL plan nodes.
- Documents: worker lifecycle, internal-target trust model, self-signed warning, domain sources, retention, backup/migration, health and troubleshooting.

- [ ] **Step 1: Add failing documentation and benchmark contracts**

```php
public function testWebsiteMonitoringOperationalDocsArePresent(): void
{
    foreach (['website-check-worker','30 days','365 days','self-signed','RDAP','WHOIS','390'] as $needle) {
        self::assertStringContainsString($needle, $this->combinedDocs());
    }
    self::assertFileIsExecutable(dirname(__DIR__, 2) . '/bin/benchmark-websites');
}
```

Contract-test both env examples for `WEBSITE_CHECK_LOOP_INTERVAL` only; user-facing thresholds live in database settings rather than unnecessary environment variables.

- [ ] **Step 2: Implement website benchmark**

Follow `bin/benchmark-dashboard`: begin a transaction, generate 50 then 1000 websites with three endpoints each, current state, active alerts and representative samples, record repository query count, run `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`, emit JSON, then roll back. Fail if list/dashboard read models exceed five SQL statements, return the wrong row count, or scan the raw hypertable for current-state summaries. Do not enforce unstable wall-clock thresholds.

- [ ] **Step 3: Update operational and architecture documentation**

Document centralized `app` probes, trusted-admin internal target model, exact check semantics, auth/header stripping, self-signed limitation, TLS/domain thresholds/sources, queue leases, worker heartbeat, retention, migrations, maintenance versus pause, manual checks, UI routes and backup expectations. State explicitly that no agent probes or third Compose service exist.

- [ ] **Step 4: Run all unit, integration, contract, static and dependency gates**

Run:

```bash
composer test
composer analyse
composer validate --strict
composer audit
npm ci
npm run assets:sync
git diff --exit-code -- public/vendor
npm audit --audit-level=high
shellcheck docker/*.sh
php -l bin/refresh-domain-data
docker compose --env-file .env -f docker/docker-compose.yml config --quiet
docker build -f docker/Dockerfile .
```

Expected: every command exits 0. The integration report must show website Timescale tests executed, not skipped.

- [ ] **Step 5: Run both performance benchmarks**

Run:

```bash
bin/benchmark-dashboard
bin/benchmark-websites
```

Expected: each emits valid JSON for 50 and 1000 objects; website summaries use at most five SQL statements and neither benchmark reports an unexpected raw hypertable scan for current state. Save the JSON in the implementation report, not in Git.

- [ ] **Step 6: Verify clean two-container deployment and health**

Run:

```bash
docker compose --env-file .env -f docker/docker-compose.yml -f docker/docker-compose.build.yml up -d --build
docker compose --env-file .env -f docker/docker-compose.yml ps
curl --fail --silent --show-error http://127.0.0.1:8080/livez
curl --fail --silent --show-error http://127.0.0.1:8080/readyz
docker compose --env-file .env -f docker/docker-compose.yml exec -T app bin/website-check-worker --once
```

Expected: only `app` and `db` services are running/healthy, endpoints return `alive`/`ready`, one-shot exits 0, and administration health shows a fresh website worker heartbeat.

- [ ] **Step 7: Perform browser smoke at desktop and 390 px**

Using the real local app and seeded HTTP/TLS/RDAP/WHOIS fixtures, verify dashboard, sites list, create, edit, overview, metrics, events, settings, groups and incidents in Russian and English. At both 1440×900 and 390×844 assert no horizontal clipping, usable endpoint form controls, working tabs/filters/chart incident links, self-signed warning visibility, no secret values in DOM/network payloads, and zero console errors. Capture one dashboard and one site-detail screenshot per viewport as non-committed evidence.

- [ ] **Step 8: Run final diff/security review**

Run:

```bash
rg -n 'Authorization|Bearer |password|secret|token|headers_encrypted|auth_encrypted' var/log tests/Fixtures || true
rg -n 'https?://' templates public/js public/css | rg -v 'localhost|example\.com' || true
git diff --check
git status --short
```

Expected: no runtime secret leakage, no CDN dependency, no whitespace errors, and only intentional feature files changed. Review every migration and route against the design specification before commit.

- [ ] **Step 9: Commit documentation and verification tooling**

```bash
git add bin/benchmark-websites README.md ARCHITECTURE.md TECHNICAL_SPECIFICATION.md INSTALL.md .env.example docker/.env.example docker/README.md docs/use-cases.md docs/troubleshooting.md tests/Contract/DocumentationContractTest.php tests/Contract/ComposeContractTest.php
git commit -m "docs: document website monitoring operations"
```

- [ ] **Step 10: Request final code review**

Use `requesting-code-review` against the complete branch, address only evidence-backed findings with `receiving-code-review`, rerun every affected focused test, then repeat the full gates from Steps 4–8 before presenting integration options through `finishing-a-development-branch`.
