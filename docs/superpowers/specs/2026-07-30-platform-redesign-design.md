# MirvMon Platform Redesign

**Status:** Approved in conversation on 2026-07-30

## Goal

Turn the current development prototype into a secure, maintainable monitoring
service that is simple to launch as a Portainer stack, accepts push metrics from
agents behind NAT over HTTPS, and stores time-series data efficiently.

There are no production users and no existing MariaDB data to migrate. The new
PostgreSQL/TimescaleDB schema is the only supported schema.

## Runtime and deployment

The supported deployment contains exactly two containers:

1. `app`: a rootless FrankenPHP container running PHP 8.5 in classic mode on
   HTTP port 8080.
2. `db`: PostgreSQL 17 with TimescaleDB, reachable only on the internal Docker
   network.

An external nginx reverse proxy terminates TLS and forwards HTTP to `app`. The
application trusts forwarded headers only from networks listed in
`TRUSTED_PROXIES`.

The Portainer entrypoint is one Compose file with:

- exact image versions rather than `latest`;
- health checks for both services;
- a persistent database volume;
- no published database port;
- configurable application port bound to the host;
- automatic, idempotent schema migration before the web process starts;
- restart policies, log rotation, and resource-friendly defaults.

FrankenPHP is a deployment adapter, not an application dependency. The
application uses PSR-7/PSR-15 requests and responses, a conventional
`public/index.php`, PDO, and separate CLI entrypoints. A future
FrankenPHP-worker adapter may be added without changing domain code. The same
application must remain runnable under nginx and PHP-FPM.

## Database design

PostgreSQL owns relational state:

- users and sessions;
- server groups and servers;
- agent identities and configuration;
- metric definitions and thresholds;
- service current state and service state-change events;
- alerts;
- notification settings and an outbox;
- schema migration history.

TimescaleDB owns time-series state:

### `metric_samples`

- `sample_time TIMESTAMPTZ NOT NULL`;
- `server_id BIGINT NOT NULL`;
- `metric_id BIGINT NOT NULL`;
- `sample_id UUID NOT NULL`;
- `value DOUBLE PRECISION NOT NULL`;
- unique identity `(server_id, sample_id, metric_id, sample_time)`;
- hypertable partitioned by `sample_time`;
- index optimized for `(server_id, metric_id, sample_time DESC)`.

Metric names are bounded, validated, and resolved in one batch. Process payloads
and other JSON are never stored in this numeric table.

### Continuous aggregates

- hourly `avg`, `min`, `max`, `count`;
- daily `avg`, `min`, `max`, `count`;
- raw retention defaults to 60 days;
- hourly retention defaults to 730 days;
- daily retention is unlimited by default;
- raw chunks move to TimescaleDB columnstore after seven days.

Retention values are deployment settings and are applied by idempotent policies.
Continuous aggregate refresh windows never overlap data already removed by raw
retention.

### `process_snapshots`

Process snapshots are stored separately as JSONB with short retention. Command
line collection is disabled by default. When enabled, the agent redacts common
secret-bearing arguments before transmission. Snapshot access requires an
authenticated user.

### Service state

`service_status` contains one current row per server and service.
`service_events` records only state transitions. Agents do not resend the full
unchanged service inventory on every metrics request.

## Agent protocol

Agents make outbound HTTPS requests to the public service. No inbound connection
to an agent is required, so agents work behind NAT.

The installer base URL is selected in this order:

1. `PUBLIC_BASE_URL`, when configured;
2. the current request scheme and host after trusted-proxy processing.

No domain is hard-coded. Host-header-derived URLs are allowed only after host
validation. Installers embed the resolved API and configuration URLs.

Agent authentication uses a random token shown once. The database stores only a
SHA-256 token hash. Download/install flows use a short-lived, one-time
installation credential rather than placing the permanent agent token in query
strings.

Each metrics envelope includes:

- protocol version;
- stable `agent_id`;
- UUID `sample_id`;
- UTC `sample_time`;
- numeric metrics;
- changed service states;
- optional redacted process snapshot.

The server validates body size, metric count, metric names, finite numeric
values, timestamp drift, and payload shape. Duplicate sample IDs are accepted
idempotently without duplicating metric rows.

The agent:

- verifies TLS certificates;
- has bounded exponential retry;
- persists a small disk queue during network outages;
- supports system HTTP/HTTPS proxy configuration;
- periodically pulls its enabled state, interval, and monitored-service
  configuration over the same outbound HTTPS channel;
- runs as a dedicated unprivileged system user on Linux;
- uses correct platform-specific configuration paths;
- installs all required, pinned Python dependencies.

## Notification architecture

Metric ingestion never waits for SMTP or Telegram. Threshold and offline state
changes create alerts and transactional outbox records. A CLI worker in the app
container claims and delivers outbox rows with bounded retries.

Telegram settings live together in the web dashboard:

- enabled state;
- bot token;
- chat ID;
- proxy enabled state;
- proxy type: HTTP, HTTPS, SOCKS4, SOCKS4A, SOCKS5, or SOCKS5H;
- proxy host and port;
- optional proxy username and password;
- connection and request timeout;
- test-message action;
- sanitized last delivery error.

Proxy configuration applies only to Telegram. The bot token and proxy password
are encrypted at rest with an application key supplied through a Docker secret
or environment variable. Secrets are never returned to the browser or logs.
The HTTP transport uses libcurl and maps the selected proxy type explicitly.

## Security

- Every browser route requires authentication except login and health probes.
- Every state-changing browser action uses POST/PUT/PATCH/DELETE and CSRF.
- Login regenerates the session ID.
- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` when the external
  request is HTTPS.
- Logout is POST.
- Login has IP/account rate limiting.
- The first administrator is created from one-time bootstrap environment
  variables or an interactive setup flow; no default password exists.
- Role checks remain explicit for administration routes.
- Agent process endpoints require browser authentication.
- The web server executes only `public/index.php`; CLI utilities are outside
  the public document root.
- Security headers include CSP, Referrer-Policy, Permissions-Policy,
  X-Content-Type-Options, and frame protection.
- Application errors have stable JSON/HTML responses; internal exceptions are
  logged but not returned to clients.
- Unknown routes return 404.
- Database and proxy credentials are not placed on process command lines.

## Application structure

The existing Slim/Twig UI remains, but construction moves out of controllers:

- `AppFactory` builds the PSR application and middleware;
- repositories own SQL;
- services own ingestion, status calculation, agent installation, and
  notification behavior;
- controllers translate HTTP input/output only;
- runtime entrypoints create dependencies;
- workers use the same services without HTTP.

The dashboard loads server state with set-based queries rather than N+1 loops.
Online/offline state uses the server's configured timeout everywhere. "Current"
metric cards select the newest sample.

## Dashboard

- Mobile server cards occupy the full width below the small breakpoint.
- Status has text/icon as well as color.
- Group colors meet WCAG AA contrast.
- Navigation controls have accessible names.
- Server search, group/status filters, and sorting are available.
- Last update is shown as relative time with an exact timestamp.
- Chart tick density adapts to the viewport.
- Frontend dependencies are pinned and served locally or through a lock-based
  build, not floating CDN URLs.

## Quality gates

- PHPUnit covers services, middleware, URL generation, proxy mapping,
  authentication, ingestion validation/idempotency, and status calculation.
- PostgreSQL integration tests run against TimescaleDB.
- PHPStan runs at an explicitly configured level and increases only through
  deliberate changes.
- Python agent tests cover redaction, queue persistence, retries, configuration,
  and platform paths.
- Composer audit, Python dependency audit, PHP syntax, Python compilation,
  Compose validation, and Docker image build run in CI.
- A smoke test starts the two-container stack, completes first-admin setup,
  installs a test agent, ingests samples, and renders dashboard/detail pages.

## Documentation

`README.md`, `INSTALL.md`, `ARCHITECTURE.md`,
`TECHNICAL_SPECIFICATION.md`, `.env.example`, `docker/README.md`, and the agent
installation help must describe only the implemented PostgreSQL/TimescaleDB and
FrankenPHP deployment.

Documentation includes:

- Portainer Stack installation;
- external nginx example;
- trusted proxy and `PUBLIC_BASE_URL` behavior;
- backup, restore, upgrade, and TimescaleDB extension-upgrade procedures;
- retention/columnstore policies;
- agent NAT/proxy behavior;
- Telegram proxy configuration;
- alternative nginx + PHP-FPM runtime requirements;
- exact supported component versions and the update/audit process.

Documentation and examples are acceptance artifacts: a feature is incomplete
until its related documentation is updated.

## Delivery and commit policy

Implementation occurs directly on `master`, as authorized by the project owner.
Changes are split into reviewable commits. Each commit is tested before push.
No production deployment or external notification is triggered by tests.
