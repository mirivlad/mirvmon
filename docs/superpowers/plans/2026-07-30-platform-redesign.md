# MirvMon Platform Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a secure two-container FrankenPHP and TimescaleDB monitoring
service with NAT-friendly push agents, asynchronous notifications, Telegram
proxy support, a responsive dashboard, current dependencies, and complete
Portainer documentation.

**Architecture:** Keep Slim and Twig behind a runtime-neutral PSR application
kernel. Replace MariaDB with PostgreSQL/TimescaleDB, separate numeric samples
from JSON snapshots, use continuous aggregates and retention policies, and move
external notifications to a transactional outbox. FrankenPHP classic mode is
the primary HTTP adapter while nginx/PHP-FPM remains a documented alternative.

**Tech Stack:** PHP 8.5, Slim 4, Twig 3, PHPUnit 13, PHPStan 2, FrankenPHP
1.12.x, PostgreSQL 17, TimescaleDB 2.28.x, Python 3.11+, psutil, requests,
Bootstrap 5, Chart.js 4, Docker Compose/Portainer.

## Global Constraints

- Work directly on `master`; commit reviewable stages and push each verified
  stage to `origin/master`.
- Do not preserve MariaDB compatibility or write a MariaDB data migrator.
- Production deployment is exactly two containers: `app` and `db`.
- Agents use outbound HTTPS through the external nginx and never require
  inbound connectivity.
- Application/domain code must not call FrankenPHP or Caddy APIs.
- Telegram proxy supports HTTP, HTTPS, SOCKS4, SOCKS4A, SOCKS5, and SOCKS5H.
- Documentation changes are part of each deliverable.
- No hard-coded service domain, bootstrap password, encryption key, or proxy
  credential.
- Every behavior change follows red-green-refactor.

---

### Task 1: Current dependency and test baseline

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `phpunit.xml`
- Create: `phpstan.neon`
- Create: `tests/bootstrap.php`
- Create: `tests/Unit/SmokeTest.php`
- Modify: `.gitignore`

**Interfaces:**
- Produces: Composer scripts `test`, `analyse`, `check`, and an autoloaded
  `Tests\` namespace used by all later tasks.

- [ ] **Step 1: Add a failing smoke test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testRuntimeMeetsSupportedVersion(): void
    {
        self::assertGreaterThanOrEqual('8.5.0', PHP_VERSION);
    }
}
```

- [ ] **Step 2: Verify the test cannot run before dev dependencies exist**

Run: `composer test`

Expected: non-zero exit because the `test` script or PHPUnit is absent.

- [ ] **Step 3: Upgrade supported dependencies and configure tools**

Set PHP to `^8.5`, update Slim/Twig to non-vulnerable current releases, add
`ext-pdo`, `ext-pdo_pgsql`, `ext-curl`, `ext-sodium`, add PHPUnit `^13.2` and
PHPStan `^2.2`, and define:

```json
"scripts": {
  "test": "phpunit",
  "analyse": "phpstan analyse --no-progress",
  "check": [
    "@test",
    "@analyse",
    "composer validate --strict",
    "composer audit"
  ]
}
```

Ignore `.phpunit.cache`, PHPStan cache, Python bytecode, generated coverage, and
local audit artifacts. Remove tracked Python bytecode.

- [ ] **Step 4: Verify dependency and tool baseline**

Run:

```bash
composer update --with-all-dependencies
composer test
composer analyse
composer audit
```

Expected: smoke test passes, PHPStan has a deliberately documented initial
level, and Composer reports zero security advisories.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock phpunit.xml phpstan.neon tests .gitignore
git add -u __pycache__
git commit -m "build: establish current PHP quality baseline"
git push origin master
```

### Task 2: PostgreSQL/TimescaleDB schema and migration runner

**Files:**
- Delete: `docker/migrations/001_create_base_schema.sql`
- Delete: `docker/migrations/002_add_encrypted_token.sql`
- Delete: `docker/migrations/003_add_agent_configs.sql`
- Delete: `docker/migrations/004_add_global_notification_settings.sql`
- Delete: `docker/migrations/005_add_service_tables.sql`
- Delete: `docker/migrations/006_server_metrics_value_to_text.sql`
- Delete: `docker/migrations/007_seed_admin_user.sql`
- Delete: `docker/migrations/008_auto_cleanup_metrics.sql`
- Delete: `docker/migrations/009_add_offline_settings.sql`
- Create: `migrations/001_initial.sql`
- Create: `migrations/002_timeseries_policies.sql`
- Create: `src/Database/ConnectionFactory.php`
- Create: `src/Database/Migrator.php`
- Create: `bin/migrate`
- Replace: `config/DatabaseConfig.php`
- Delete: `config/DatabaseConfig.php.bak.20260414`
- Test: `tests/Unit/Database/ConnectionFactoryTest.php`
- Test: `tests/Integration/Database/SchemaTest.php`

**Interfaces:**
- Produces: `ConnectionFactory::fromEnvironment(): PDO`,
  `Migrator::migrate(): void`, and the relational/time-series tables in the
  approved design.

- [ ] **Step 1: Write failing connection configuration tests**

Test that PostgreSQL DSNs include host, port, database, `sslmode`, throw on
missing credentials, and never fall back to known passwords.

- [ ] **Step 2: Run the focused tests and confirm RED**

Run:

```bash
vendor/bin/phpunit tests/Unit/Database/ConnectionFactoryTest.php
```

Expected: failure because `ConnectionFactory` does not exist.

- [ ] **Step 3: Implement the connection factory**

Build a `pgsql:` DSN from `DB_HOST`, `DB_PORT`, `DB_NAME`, and `DB_SSLMODE`;
read username/password separately; enable exceptions, associative fetches, and
native prepares.

- [ ] **Step 4: Write the schema and migrator integration tests**

Assert:

```sql
SELECT extversion FROM pg_extension WHERE extname = 'timescaledb';
SELECT hypertable_name FROM timescaledb_information.hypertables
 WHERE hypertable_name IN ('metric_samples', 'process_snapshots');
SELECT view_name FROM timescaledb_information.continuous_aggregates
 WHERE view_name IN ('metric_samples_hourly', 'metric_samples_daily');
```

Also assert foreign keys, unique sample identity, notification outbox, encrypted
secret columns, migration history, and absence of a seeded administrator.

- [ ] **Step 5: Run the integration test and confirm RED**

Run: `composer test -- --testsuite integration --filter SchemaTest`

Expected: failure because migrations and TimescaleDB are absent.

- [ ] **Step 6: Implement initial schema and policies**

Use PostgreSQL types (`BIGSERIAL`, `TIMESTAMPTZ`, `JSONB`, `BOOLEAN`) and
Timescale APIs:

```sql
CREATE EXTENSION IF NOT EXISTS timescaledb;
SELECT create_hypertable('metric_samples', by_range('sample_time'),
                         if_not_exists => TRUE);
CREATE MATERIALIZED VIEW metric_samples_hourly
WITH (timescaledb.continuous) AS
SELECT time_bucket(INTERVAL '1 hour', sample_time) AS bucket,
       server_id, metric_id,
       avg(value) AS avg_value, min(value) AS min_value,
       max(value) AS max_value, count(*) AS sample_count
FROM metric_samples
GROUP BY bucket, server_id, metric_id
WITH NO DATA;
```

Add idempotent refresh, retention, and columnstore policies. The migrator holds
a PostgreSQL advisory lock, records checksums, and refuses a changed migration.

- [ ] **Step 7: Verify clean and repeated migration**

Run the migration twice against a disposable TimescaleDB database, then run the
full schema integration suite.

Expected: both migration invocations succeed and the second applies zero files.

- [ ] **Step 8: Commit**

```bash
git add migrations src/Database config/DatabaseConfig.php bin/migrate tests
git add -u docker/migrations config
git commit -m "feat: replace MariaDB schema with TimescaleDB"
git push origin master
```

### Task 3: Two-container FrankenPHP/Portainer runtime

**Files:**
- Replace: `docker/Dockerfile`
- Replace: `docker/docker-compose.yml`
- Create: `docker/Caddyfile`
- Create: `docker/php.ini`
- Create: `docker/supervisord.conf`
- Create: `docker/entrypoint.sh`
- Delete: `docker/init.sh`
- Replace: `.env.example`
- Replace: `docker/.env.example`
- Delete: `docker/nginx.conf`
- Test: `tests/Contract/ComposeContractTest.php`

**Interfaces:**
- Produces: HTTP application at container port 8080 and an internal-only
  TimescaleDB service.

- [ ] **Step 1: Write a failing Compose contract test**

Parse the Compose YAML and assert exactly `app` and `db`, no published DB port,
fixed images, app health check, DB health check, persistent DB volume, and
`SERVER_NAME=:8080`.

- [ ] **Step 2: Confirm RED**

Run: `vendor/bin/phpunit tests/Contract/ComposeContractTest.php`

Expected: failure because the current stack has app/nginx/db and MariaDB.

- [ ] **Step 3: Implement the runtime**

Use a multi-stage image based on
`dunglas/frankenphp:1.12.6-php8.5-trixie`, install current locked Composer
dependencies and PHP extensions, switch to an unprivileged user, and configure
classic mode only. Use `timescale/timescaledb:2.28.3-pg17` for `db`.

The entrypoint waits with `pg_isready`, runs `bin/migrate`, performs optional
first-admin bootstrap, then starts supervisor. Supervisor runs FrankenPHP,
outbox worker, and offline worker with graceful shutdown.

- [ ] **Step 4: Validate configuration**

Run:

```bash
docker compose --env-file .env.example -f docker/docker-compose.yml config
vendor/bin/phpunit tests/Contract/ComposeContractTest.php
docker build -f docker/Dockerfile .
```

Expected: all succeed without floating image tags.

- [ ] **Step 5: Commit**

```bash
git add docker .env.example tests/Contract
git commit -m "build: add two-container FrankenPHP Portainer stack"
git push origin master
```

### Task 4: Runtime-neutral application kernel and HTTP security

**Files:**
- Create: `src/Application/AppFactory.php`
- Create: `src/Application/Container.php`
- Create: `src/Http/ErrorResponder.php`
- Create: `src/Middlewares/SecurityHeadersMiddleware.php`
- Create: `src/Middlewares/TrustedProxyMiddleware.php`
- Create: `src/Middlewares/SessionSecurityMiddleware.php`
- Replace: `public/index.php`
- Delete: `public/check_offline.php`
- Modify: all state-changing route definitions and affected templates
- Test: `tests/Functional/HttpSecurityTest.php`
- Test: `tests/Unit/Http/ErrorResponderTest.php`
- Test: `tests/Unit/Middleware/TrustedProxyMiddlewareTest.php`

**Interfaces:**
- Produces: `AppFactory::create(ContainerInterface $container): App`, stable
  HTML/JSON error responses, trusted external scheme/host, secure sessions, and
  CSRF-protected mutation routes.

- [ ] **Step 1: Add failing security tests**

Cover 404 vs 500, authenticated process endpoint, CSRF rejection, POST logout,
session-ID rotation, cookie attributes, security headers, untrusted forwarded
headers, and body-size rejection.

- [ ] **Step 2: Confirm RED**

Run: `vendor/bin/phpunit tests/Functional/HttpSecurityTest.php`

Expected: failures reproducing the audited behavior.

- [ ] **Step 3: Extract the application kernel**

Move environment bootstrap, dependency construction, routes, middleware, and
error middleware out of the front controller. Keep only:

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App\Application\Bootstrap::fromEnvironment()->app();
$app->run();
```

- [ ] **Step 4: Implement security middleware and route semantics**

Generate CSRF tokens server-side, use mutation verbs, regenerate sessions after
login, configure cookie flags from trusted external HTTPS state, rate-limit
login attempts, require auth for process data, and remove public PHP utilities.

- [ ] **Step 5: Verify HTTP security**

Run the focused suite and a route-list contract test. Expected: protected and
mutation routes have the required middleware; unknown routes return 404.

- [ ] **Step 6: Commit**

```bash
git add public src/Application src/Http src/Middlewares templates tests
git add -u public
git commit -m "refactor: add runtime-neutral secure HTTP kernel"
git push origin master
```

### Task 5: Set-based repositories and correct dashboard behavior

**Files:**
- Create: `src/Repositories/ServerRepository.php`
- Create: `src/Repositories/MetricRepository.php`
- Create: `src/Services/ServerStatusService.php`
- Modify: `src/Controllers/DashboardController.php`
- Modify: `src/Controllers/ServerDetailController.php`
- Modify: `src/Controllers/Api/MetricsApiController.php`
- Retire SQL from: `src/Models/Server.php`
- Test: `tests/Integration/Repositories/ServerRepositoryTest.php`
- Test: `tests/Integration/Repositories/MetricRepositoryTest.php`
- Test: `tests/Unit/Services/ServerStatusServiceTest.php`

**Interfaces:**
- Produces: one set-based dashboard query, consistent per-server offline
  status, newest-current metrics, and raw/continuous-aggregate period queries.

- [ ] **Step 1: Write failing correctness and query-count tests**

Create stale/fresh server fixtures and assert summary/card agreement. Insert
ascending samples and assert the newest is current. Assert dashboard loading
does not scale query count with server count.

- [ ] **Step 2: Confirm RED**

Run the three focused test files. Expected: online mismatch, oldest-current
selection, and N+1 query count failures.

- [ ] **Step 3: Implement repositories and status service**

Use PostgreSQL CTEs and `DISTINCT ON`/window functions for latest values and
alerts. Select raw data for short periods, hourly aggregate for medium periods,
and daily aggregate for long periods.

- [ ] **Step 4: Verify behavior and explain plans**

Run integration tests and capture `EXPLAIN (ANALYZE, BUFFERS)` fixtures for 50
and 1,000 synthetic servers. Expected: bounded query count and indexed time
range scans.

- [ ] **Step 5: Commit**

```bash
git add src/Repositories src/Services/ServerStatusService.php \
  src/Controllers src/Models/Server.php tests
git commit -m "perf: replace dashboard N+1 queries with set-based reads"
git push origin master
```

### Task 6: Idempotent ingestion and asynchronous alerts

**Files:**
- Create: `src/Domain/Metrics/MetricsEnvelope.php`
- Create: `src/Domain/Metrics/MetricsValidator.php`
- Create: `src/Services/MetricsIngestionService.php`
- Create: `src/Services/ThresholdEvaluator.php`
- Create: `src/Repositories/NotificationOutboxRepository.php`
- Create: `bin/notification-worker`
- Create: `bin/offline-worker`
- Refactor: `src/Controllers/Api/MetricsController.php`
- Test: `tests/Unit/Domain/Metrics/MetricsValidatorTest.php`
- Test: `tests/Integration/Services/MetricsIngestionServiceTest.php`
- Test: `tests/Integration/Workers/NotificationWorkerTest.php`

**Interfaces:**
- Consumes: database schema and repository layer.
- Produces: validated protocol v2 ingestion, transactional idempotency,
  threshold state transitions, and asynchronous notification delivery.

- [ ] **Step 1: Write failing envelope validation tests**

Cover protocol version, UUIDs, UTC timestamps, finite doubles, metric-name
grammar, maximum 100 metrics, maximum snapshot size, clock drift, and unknown
fields.

- [ ] **Step 2: Confirm RED**

Run: `vendor/bin/phpunit tests/Unit/Domain/Metrics/MetricsValidatorTest.php`

Expected: failure because envelope classes do not exist.

- [ ] **Step 3: Implement validation and one-transaction ingestion**

Resolve metrics in a batch, insert numeric rows with `ON CONFLICT DO NOTHING`,
upsert changed service states, store optional snapshots separately, update
`last_metrics_at` from accepted sample time, create alerts/outbox rows only on
state transitions, and return duplicate status deterministically.

- [ ] **Step 4: Verify idempotency and asynchronous behavior**

Post the same envelope twice and assert one set of rows and one alert/outbox
event. Use a deliberately unavailable Telegram endpoint and assert ingestion
still completes within the HTTP budget.

- [ ] **Step 5: Implement and verify workers**

Use `FOR UPDATE SKIP LOCKED`, attempt counters, exponential retry timestamps,
sanitized errors, and graceful SIGTERM. Test concurrent claims and offline
transitions.

- [ ] **Step 6: Commit**

```bash
git add src/Domain src/Services src/Repositories \
  src/Controllers/Api/MetricsController.php bin tests
git commit -m "feat: add idempotent ingestion and notification outbox"
git push origin master
```

### Task 7: NAT-friendly agent, dynamic service URL, and safe installers

**Files:**
- Refactor: `agent.py`
- Create: `agent/requirements.txt`
- Create: `agent/mirvmon_agent/__init__.py`
- Create: `agent/mirvmon_agent/config.py`
- Create: `agent/mirvmon_agent/client.py`
- Create: `agent/mirvmon_agent/queue.py`
- Create: `agent/mirvmon_agent/collectors.py`
- Create: `agent/mirvmon_agent/redaction.py`
- Create: `src/Services/PublicUrlResolver.php`
- Create: `src/Services/AgentInstallerService.php`
- Refactor: `src/Controllers/AgentController.php`
- Test: `tests/Unit/Services/PublicUrlResolverTest.php`
- Test: `tests/Unit/Services/AgentInstallerServiceTest.php`
- Test: `agent/tests/test_client.py`
- Test: `agent/tests/test_queue.py`
- Test: `agent/tests/test_redaction.py`

**Interfaces:**
- Produces: dynamic public URL resolution, one-time installer credentials,
  Linux/Windows installers, config pull, persistent retry queue, and protocol
  v2 envelopes.

- [ ] **Step 1: Write failing URL resolver and installer tests**

Assert explicit `PUBLIC_BASE_URL` precedence, trusted forwarded host fallback,
rejection of untrusted host headers, no query-string permanent token, Linux
dedicated user, required Python packages, and correct Windows config path.

- [ ] **Step 2: Confirm PHP RED**

Run the two focused PHPUnit files. Expected: current hard-coded domain and
installer defects fail assertions.

- [ ] **Step 3: Implement URL and installer services**

Keep template generation outside the controller. Consume one-time installer
credentials once, generate an agent token locally in the response flow, store
only its hash, and emit platform-correct installation scripts.

- [ ] **Step 4: Write and confirm failing Python tests**

Test proxy-aware HTTP sessions, TLS verification, sample IDs, queue persistence
across restart, bounded queue size, exponential retry, config pull, changed-only
services, and secret redaction.

- [ ] **Step 5: Implement the agent package**

Make `agent.py` a compatibility CLI wrapper. Use pinned requirements, atomic
JSON queue files with mode 0600, standard proxy environment support, and
platform configuration directories.

- [ ] **Step 6: Verify installers in disposable Linux and Windows-script checks**

Run Linux installation in a disposable container and PowerShell syntax/parser
validation where available. Assert all generated API URLs use the download
origin or configured public base.

- [ ] **Step 7: Commit**

```bash
git add agent.py agent src/Services src/Controllers/AgentController.php tests
git commit -m "feat: harden NAT-friendly agent installation and delivery"
git push origin master
```

### Task 8: Telegram proxy transport and notification settings

**Files:**
- Create: `src/Notifications/NotificationChannel.php`
- Create: `src/Notifications/TelegramTransport.php`
- Create: `src/Notifications/SmtpTransport.php`
- Create: `src/Security/SecretCipher.php`
- Refactor: `src/Services/NotificationService.php`
- Modify: `src/Controllers/AdminController.php`
- Modify: `templates/admin/notifications.twig`
- Test: `tests/Unit/Notifications/TelegramTransportTest.php`
- Test: `tests/Unit/Security/SecretCipherTest.php`
- Test: `tests/Functional/TelegramSettingsTest.php`

**Interfaces:**
- Produces: Telegram-only proxy configuration with all agreed libcurl proxy
  types, encrypted secrets, sanitized errors, and an authenticated CSRF-protected
  test action.

- [ ] **Step 1: Write failing proxy mapping tests**

Assert exact mappings to `CURLPROXY_HTTP`, `CURLPROXY_HTTPS`,
`CURLPROXY_SOCKS4`, `CURLPROXY_SOCKS4A`, `CURLPROXY_SOCKS5`, and
`CURLPROXY_SOCKS5_HOSTNAME`; reject unsupported schemes, invalid ports, and
partial credentials.

- [ ] **Step 2: Confirm RED**

Run: `vendor/bin/phpunit tests/Unit/Notifications/TelegramTransportTest.php`

Expected: failure because the transport does not exist.

- [ ] **Step 3: Implement secret and Telegram transports**

Use sodium authenticated encryption with key versioning. Configure cURL options
without embedding credentials in the proxy URL. Escape Telegram HTML, apply
timeouts, and sanitize cURL errors before persistence.

- [ ] **Step 4: Implement and verify dashboard settings**

Never return stored token/password values. Preserve a secret when a masked field
is submitted unchanged; allow an explicit clear action. Test CSRF, admin role,
proxy type validation, and the test-message outbox path.

- [ ] **Step 5: Commit**

```bash
git add src/Notifications src/Security src/Services/NotificationService.php \
  src/Controllers/AdminController.php templates/admin/notifications.twig tests
git commit -m "feat: add secure Telegram proxy configuration"
git push origin master
```

### Task 9: Responsive and accessible dashboard

**Files:**
- Modify: `templates/dashboard.twig`
- Modify: `templates/layout.twig`
- Modify: `templates/servers/detail.twig`
- Create/modify: `public/css/app.css`
- Create/modify: `public/js/dashboard.js`
- Create/modify: `public/js/server-detail.js`
- Test: `tests/Functional/DashboardRenderTest.php`
- Test: `tests/Browser/dashboard.spec.js`

**Interfaces:**
- Consumes: corrected set-based dashboard and metric data.
- Produces: accessible responsive status cards, search/filter/sort, relative
  time, adaptive charts, and locally pinned frontend assets.

- [ ] **Step 1: Add failing render/browser assertions**

At 390px assert one server card per row and no clipped metrics. Assert named
navigation toggler, textual status, sufficient group-header contrast,
search/filter controls, newest current timestamp, and no external CDN requests.

- [ ] **Step 2: Confirm RED**

Run functional tests and Playwright test. Expected: mobile/card/accessibility/CDN
assertions fail against the audited UI.

- [ ] **Step 3: Implement the minimal responsive UI**

Move inline assets to focused files, use full-width mobile cards, add accessible
labels and status text, select contrast-aware text color, add client-side
search/filter/sort, render exact and relative timestamps, and limit chart ticks
per viewport.

- [ ] **Step 4: Verify desktop, mobile, and keyboard flows**

Capture desktop/mobile/detail screenshots, run automated accessibility checks,
and verify zero console errors, failed assets, or horizontal overflow.

- [ ] **Step 5: Commit**

```bash
git add templates public tests
git commit -m "feat: improve dashboard responsiveness and accessibility"
git push origin master
```

### Task 10: Documentation, CI, backup, and final stack verification

**Files:**
- Replace: `README.md`
- Replace: `INSTALL.md`
- Replace: `ARCHITECTURE.md`
- Replace: `TECHNICAL_SPECIFICATION.md`
- Replace: `docker/README.md`
- Replace: `docker/deploy.sh`
- Create: `docker/backup.sh`
- Create: `docker/restore.sh`
- Create: `.github/workflows/ci.yml`
- Create: `docs/UPGRADING.md`
- Create: `docs/OPERATIONS.md`
- Create: `tests/smoke/stack-smoke.sh`

**Interfaces:**
- Produces: current operator/developer documentation, verified backup/restore,
  dependency audit automation, and one-command Portainer-compatible deployment.

- [ ] **Step 1: Add failing documentation and stack contract checks**

Reject references to MariaDB, MySQL, default passwords, the unrelated domain,
floating Docker tags, public DB ports, and obsolete cron aggregation.

- [ ] **Step 2: Confirm RED**

Run the documentation contract test. Expected: current documents contain all
rejected legacy instructions.

- [ ] **Step 3: Rewrite documentation and operations scripts**

Document Portainer, external nginx headers, trusted proxies, public URL
resolution, first-admin setup, Timescale policies, backup/restore with
`pg_dump`/`pg_restore`, extension upgrades, agent NAT/proxies, Telegram proxy,
and PHP-FPM alternative runtime.

- [ ] **Step 4: Add CI**

CI starts TimescaleDB, runs migrations twice, PHPUnit, PHPStan, Composer audit,
Python tests/compile/audit, shell syntax, Compose validation, Docker build, and
the stack smoke test.

- [ ] **Step 5: Run full verification**

Run:

```bash
composer check
python3 -m unittest discover -s agent/tests
python3 -m compileall -q agent.py agent
find docker bin tests/smoke -type f -name '*.sh' -exec bash -n {} +
docker compose --env-file .env.example -f docker/docker-compose.yml config
docker compose --env-file .env.example -p mirvmon-verify \
  -f docker/docker-compose.yml up -d --build --wait
bash tests/smoke/stack-smoke.sh
docker compose --env-file .env.example -p mirvmon-verify \
  -f docker/docker-compose.yml down --volumes
```

Expected: every command exits zero; smoke test bootstraps an administrator,
registers a server, ingests duplicate and fresh samples, renders dashboard and
detail pages, and proves duplicate samples are not stored twice.

- [ ] **Step 6: Performance verification**

Seed 1,000 servers and representative 60-day aggregates. Verify dashboard API
p95 under the agreed local test budget, bounded SQL query count, ingestion
throughput, worker retry behavior, and no synchronous external calls in the
ingestion trace.

- [ ] **Step 7: Final audit and commit**

Run repository-wide searches for hard-coded domains, MySQL syntax, public
process routes, GET mutations, floating versions, secrets, and stale
documentation. Review `git diff`, rerun the full verification commands, then:

```bash
git add .
git commit -m "docs: complete TimescaleDB operations and verification guide"
git push origin master
```
