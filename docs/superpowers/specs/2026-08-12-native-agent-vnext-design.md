# MirvMon Native Agent vNext

**Status:** Approved in conversation on 2026-08-12

## Goal

Replace the Python and PowerShell MirvMon agents with one autonomous native Go
agent for supported x86-64 Linux and Windows systems. The replacement must keep
the existing node identity and server history, preserve protocol v2 semantics,
survive network outages, and upgrade transactionally without allowing the old
and new agents to run concurrently.

The current task includes only the minimum server-side work needed to distribute
the binaries and accept the agent-reported operating-system version. It does not
include any UI changes.

## Scope and non-goals

This project delivers:

- one Go source tree with shared protocol, configuration, queue, transport, and
  lifecycle code;
- Linux and Windows platform collectors with the same functional contract;
- native x86-64 binaries that do not require Python, PowerShell at runtime,
  `pip`, or separately installed libraries;
- self-contained Linux, PowerShell, and BAT installers;
- transactional migration from every currently shipped Python or PowerShell
  MirvMon agent;
- server-side binary distribution, checksum publication, protocol validation,
  and persistence of `os_version`;
- automated contract, integration, migration, and failure-path tests;
- documentation of the new installation and upgrade process.

This project does not deliver:

- 32-bit x86 binaries;
- ARM binaries;
- support for Windows Server 2008 without R2 or any Windows version older than
  Windows 7 SP1;
- an agent listener, inbound polling, or any required inbound port;
- dashboard or server-detail UI changes for `os_version`;
- new server metrics, alert types, external probes, or protocol v3;
- compatibility layers for third-party agents not previously shipped by
  MirvMon.

## Supported operating systems

Every supported target is x86-64/amd64.

### Linux

- Debian 10, 11, 12, and 13;
- Ubuntu 16.04, 18.04, 20.04, 22.04, and 24.04;
- CentOS 7;
- NethServer 7 on CentOS 7;
- RHEL 7, 8, and 9;
- Oracle Linux 7, 8, and 9;
- AlmaLinux 8 and 9;
- Rocky Linux 8 and 9;
- CentOS Stream 8, 9, and 10;
- MX Linux 23 and 25 x86-64 releases, including their supported systemd and
  SysVinit boot modes.

The Linux binary is built with `CGO_ENABLED=0` and `GOAMD64=v1`. It relies only
on Linux kernel interfaces and the system CA store, not on the target system's
glibc version.

### Windows

- Windows 7 SP1;
- Windows 10 and 11;
- Windows Server 2008 R2 SP1;
- Windows Server 2012 and 2012 R2;
- Windows Server 2016, 2019, 2022, and 2025.

Windows Server 2008 without R2 is explicitly unsupported.

## Toolchain and compatibility strategy

The initial release uses two pinned compiler profiles:

- Go 1.26.5 for Linux and for Windows 10/11 and Windows Server 2016 or newer;
- Go 1.20.14 for Windows 7 SP1, Windows Server 2008 R2 SP1, and Windows Server
  2012/2012 R2.

Both Windows binaries are compiled from the same source revision. Shared code
must compile under Go 1.20.14, so the module's language version and dependencies
must not require a newer compiler. Files that require newer operating-system
APIs are isolated behind build tags or platform interfaces and cannot alter the
public agent behavior.

Updating the modern compiler is normal maintenance. Updating the legacy
compiler is allowed only to a release that still runs on every legacy Windows
target. A legacy build may not omit a feature merely because its compiler is
older. CI runs the same behavioral contract against both Windows build
profiles.

## Agent architecture

The agent is split into independently testable units:

- command/lifecycle: validates configuration, runs one collection cycle or the
  long-running service loop, handles shutdown, and reports local health;
- configuration: atomically loads local settings and applies validated remote
  `GET /api/v1/agent/config` updates;
- protocol: creates protocol v2 envelopes with stable field names and units;
- queue: durably stores complete envelopes before delivery;
- transport: sends metrics and pulls configuration over outbound HTTPS;
- common collector coordinator: combines platform measurements and service
  state changes;
- Linux collector: reads `/proc`, `/sys`, mount data, systemd, and SysVinit;
- Windows collector: uses native Windows APIs, the registry, performance
  counters, Service Control Manager, and WMI/COM where necessary;
- migration: recognizes and imports state from previously shipped agents;
- installer integration: exposes preflight, one-shot verification, service
  health, and version commands without opening a network listener.

Platform collectors return common typed records. Protocol serialization cannot
depend on which collector produced them. No collector may execute PowerShell,
parse localized Windows command output, or require Python.

## Functional contract

Every supported agent build provides:

- `cpu_load`, as percent;
- `ram_used`, as percent;
- `ram_total_gb`;
- `uptime`, in seconds;
- `disk_used` for the system/root disk;
- `disk_used_<name>` and `disk_total_gb_<name>` for local fixed filesystems;
- disk read and write throughput with stable protocol-v2-compatible metric
  names and bytes-per-second units;
- `net_in_<name>` and `net_out_<name>` per active non-loopback interface, in
  bytes per second;
- service inventory and changed states using the existing `running`, `stopped`,
  and `unknown` values;
- `process_snapshot.top_cpu` and `process_snapshot.top_memory`, each limited to
  20 records;
- remote enabled state, collection interval, and monitored-service
  configuration;
- durable offline queuing, retry, proxy support, and TLS verification;
- `agent_version` in every newly collected envelope;
- normalized operating-system identification exposed internally and sent as
  `os_version` once the corresponding server change in this project is active.

Temperature metrics are best-effort because neither the sensor nor a stable OS
API is guaranteed. Failure to read a temperature does not fail a sample.
Protected process command lines may be empty, but the accessible process name,
PID, and usage value remain in the snapshot. Individual optional measurement
failures are logged without secrets and do not suppress the required metrics.

Service-change behavior remains compatible with the current server: the first
sample reports the discovered service state, later samples report only changes,
and server-side `monitor_services` continues to decide which stored services
can generate alerts.

## Protocol and operating-system version

The agent continues to send protocol v2. Existing names, units, limits, HTTP
status semantics, UUID `sample_id`, UTC `sample_time`, `services`, and
`process_snapshot` remain unchanged.

Protocol v2 gains one optional top-level field:

```json
{
  "os_version": "NethServer 7.9.2009"
}
```

`os_version` is a display-oriented normalized string, not a metric. Examples
include `Debian GNU/Linux 12 (bookworm)`, `NethServer 7.9.2009`, and
`Windows Server 2008 R2 SP1 (build 7601)`. It is limited to 255 UTF-8 bytes,
cannot be empty, and cannot contain control characters.

Linux identification prefers `/etc/os-release`, with distribution-specific
release files as fallbacks for systems such as CentOS 7 and NethServer 7.
Windows identification uses version APIs and registry product data and includes
the product name, service pack where present, and build number. It must not use
the version of the compiler host or parse localized shell output.

The field is optional on the server so existing Python and PowerShell agents
remain valid. A newly collected Go-agent sample always includes it. Queued
envelopes imported from an older agent are not retroactively given an OS
version because changing an already-created payload is unnecessary except for
credential migration.

## Configuration

The Go agent reads the existing JSON configuration shape and paths:

- Linux: `/etc/mirvmon-agent/config.json`;
- Windows: `%ProgramData%\MirvMon\Agent\config.json`.

It preserves `api_url`, `config_url`, `token`, `interval_seconds`,
`verify_tls`, `queue_path`, `collect_process_commands`, `queue_limit`, and the
validated remotely supplied `enabled` and `monitor_services` values. During
migration, the installer retains every unknown key and value from the original
JSON object while replacing only recognized values that must change, such as an
explicitly rotated token or native queue path. The runtime ignores those
retained unknown keys. Configuration replacement is atomic, permissions are
owner/service-only, and validation occurs before the active file is replaced.

Remote configuration is pulled every five minutes, matching current behavior.
Invalid or unavailable remote configuration leaves the last valid local/runtime
configuration active.

## Queue and delivery behavior

Each new envelope is written atomically to persistent storage before its first
network attempt. The queue:

- contains at most 1000 complete envelopes by default;
- sends oldest first;
- retains the same `sample_id` and `sample_time` across retries;
- survives process and machine restart;
- uses an atomic replace plus a durable file sync and service-only permissions;
- quarantines corrupt queue storage instead of silently overwriting it;
- deduplicates imported entries by `sample_id` while preserving their original
  order.

Delivery classification is:

- HTTP 200 or 202: accepted and removed from the queue;
- HTTP 400, 413, or 422: removed from the active queue and written to a bounded
  local quarantine with a sanitized reason;
- HTTP 401 or 403: retained, collection pauses before the queue limit can evict
  useful history, and the agent reports an authentication health error;
- HTTP 408, 429, 5xx, timeouts, DNS errors, TLS errors, and connection failures:
  retained and retried with bounded exponential backoff.

The response body is bounded. Cross-origin redirects are not followed. Bearer
credentials used by the configuration request cannot be forwarded to another
origin. System `HTTP_PROXY`, `HTTPS_PROXY`, and `NO_PROXY` behavior remains
available. TLS certificate and hostname verification are enabled by default and
cannot be disabled by installer convenience paths.

Tokens, proxy credentials, URL query secrets, process secrets, and complete
envelopes are never written to logs. Process commands continue to use the
existing redaction rules when collection is enabled.

## Binary artifacts and server distribution

Each release produces exactly these agent executables:

- `mirvmon-agent-linux-amd64`;
- `mirvmon-agent-windows-amd64.exe`;
- `mirvmon-agent-windows-legacy-amd64.exe`.

`CGO_ENABLED=0` and `GOAMD64=v1` are used for all profiles. Linker flags embed
the MirvMon agent version and Git commit. Builds are trimmed and do not contain
credentials or deployment URLs.

The application image contains the three pinned artifacts and a build-generated
SHA-256 manifest. Public download routes serve only exact known artifact names
and never accept filesystem paths. The generated installer embeds the expected
artifact checksum and refuses to switch services when the downloaded bytes do
not match it.

Agent binaries are public in the same way as the current agent sources. The
short-lived, one-time installer credential protects only installer generation.
The permanent agent token is placed inside the response body/configuration and
never in a download URL. `PUBLIC_BASE_URL` and trusted request origin behavior
remain unchanged.

## Installation and transactional migration

The Linux installer remains POSIX `sh`. The Windows PowerShell installer is
compatible with PowerShell 2.0, and the BAT installer remains a wrapper around
the same implementation. The existing legacy Windows installer URLs remain as
compatibility aliases during migration rather than installing a separate
PowerShell collector.

The Windows installer detects the OS version and selects the modern or legacy
x86-64 binary. Every installer rejects a non-x86-64 machine before changing
state.

Installation follows this transaction:

1. Validate administrator/root privileges, OS, architecture, public URL, and
   the selected artifact's availability.
2. Detect the currently shipped Linux systemd/SysV Python agent, Windows Python
   scheduled task, Windows legacy PowerShell scheduled task, or an existing Go
   service.
3. While the existing agent is still running, download to staging, verify
   SHA-256, run the native preflight command, and back up configuration, queue,
   executable files, and service definitions.
4. Stop the old service/task and verify its process is no longer running.
5. Import configuration and queue state, install the native release beside the
   backup, and atomically switch the service definition.
6. Start the native agent and wait for successful configuration loading,
   collection of a full sample, and an accepted server delivery recorded in a
   permission-protected local health file.
7. Only after verification, remove the old virtual environment, Python source,
   PowerShell collector, obsolete scheduled task/unit, and transient backup.

Linux runs as the existing unprivileged `mirvmon-agent` user and selects systemd
only when systemd is PID 1; otherwise it installs an LSB-compatible SysVinit
service. Windows installs a real Service Control Manager service running as
LocalSystem, with code under `%ProgramFiles%\MirvMon\Agent` and state under
`%ProgramData%\MirvMon\Agent`.

The importer understands the current JSON `queue.json` and the legacy Windows
line-oriented `queue.txt`. It preserves each envelope's `sample_id` and
`sample_time`. If the installer credential supplies a different permanent
agent token after an explicit server-side rotation, the importer replaces only
the queued envelope credential; otherwise the queued bytes retain their
meaning. Samples already older than the protocol's seven-day limit are moved to
quarantine rather than retried.

At no point may the old and new agents send concurrently.

### Rollback

Any failure after the old agent stops triggers rollback:

1. Stop and disable the native service.
2. Restore the exact backed-up configuration, queue, files, and service/task
   definition.
3. Start only the old agent and verify that its process is running.
4. Keep the failed staged native release and sanitized diagnostic log for
   investigation, but remove copied credentials from diagnostic material.
5. Exit nonzero and report the failed stage without printing secrets.

If the native agent successfully delivered a sample but cleanup later fails,
the installer does not roll back to a concurrent old agent. It leaves the
verified native service active, reports an incomplete cleanup, and preserves
the old files inertly for a later retry.

Reinstalling an existing Go agent uses the same staging, verification, atomic
switch, and rollback process. A newly rotated token invalidates the old queued
credential, so queued envelopes are migrated before the new release starts.

## Minimal server changes

Server work is deliberately limited to the vertical slice needed by the native
agent:

- add exact binary download routes and include the artifacts in the production
  image;
- change generated Linux/PowerShell/BAT installers to install the selected
  native binary transactionally;
- keep old installer endpoints as compatible entry points where documented;
- extend the strict protocol-v2 validator with optional `os_version`;
- extend `MetricsEnvelope` with the normalized optional value;
- add a new checksum-protected migration with nullable
  `servers.os_version VARCHAR(255)`;
- update ingestion to persist the value from an accepted sample without
  clearing an existing value when an old agent omits it;
- add unit, integration, route-security, and artifact-contract coverage.

The server continues accepting envelopes that omit both `agent_version` and
`os_version`. Unknown fields other than the explicitly added field remain
rejected. No Twig template, controller read model, dashboard query, or UI copy
is changed in this task.

## Testing and release gates

### Automated tests

- Unit tests cover protocol serialization, field limits, OS normalization,
  configuration, redaction, queue atomicity, corruption quarantine, retry
  classification, backoff, and service-change tracking.
- Shared contract fixtures run through Linux, modern Windows, and legacy
  Windows builds and assert identical envelope names, units, limits, and HTTP
  behavior.
- Both Go 1.20.14 and Go 1.26.5 compile the shared module in CI.
- Race tests run on modern Linux and Windows builds.
- Collector tests use recorded platform fixtures plus native smoke assertions;
  they do not depend only on mocks of the final envelope.
- Queue scenarios cover outage accumulation, restart, ambiguous delivery,
  recovery, ordering, duplicate `sample_id`, permanent rejection, and
  authentication failure.
- Installer tests cover clean installation, every shipped old-agent format,
  same-token and rotated-token migration, checksum failure, failed service
  start, failed delivery, rollback, and cleanup failure after verification.
- PHP tests cover optional `os_version`, its invalid forms, persistence,
  duplicate samples, old-agent compatibility, exact artifact routes, installer
  credential handling, and path traversal rejection.
- An end-to-end test performs installation, collection, HTTPS ingestion,
  database persistence, outage queuing, recovery, and duplicate suppression.

### Platform smoke matrix

Every listed operating-system family must have a recorded native or VM smoke
result before support is claimed. A smoke test verifies install/upgrade,
service restart, required metrics, services, process snapshot, proxy/TLS where
applicable, offline queue recovery, and uninstall/rollback artifacts.

Representative automated images may cover compatible Linux families, but
NethServer 7, SysVinit MX Linux, Windows 7 SP1, and Windows Server 2008 R2 SP1
require real VM smoke runs. EOL targets may use a controlled self-hosted/manual
test environment because public hosted runners are not dependable for them.
Lack of a recorded result means the release is not declared supported for that
target.

The existing PHP, integration, frontend, Compose, container, documentation, and
security checks remain mandatory. A clean two-container deployment must pass
`/livez` and `/readyz`, and the production image must contain exactly the
manifested agent artifacts.

## Documentation

`README.md`, `INSTALL.md`, `ARCHITECTURE.md`, `TECHNICAL_SPECIFICATION.md`,
`CHANGELOG.md`, and `docker/README.md` are updated together with behavior.
Documentation must:

- replace Python prerequisites with the native-agent installation flow;
- list the exact x86-64 support matrix and explicitly exclude Windows Server
  2008 without R2;
- explain modern and legacy Windows build profiles without implying reduced
  functionality;
- describe detection, config/queue migration, verification, rollback, and safe
  retry after failure;
- describe binary checksums and public artifact routes without exposing a real
  deployment domain;
- explain that `os_version` is stored but not displayed until a later UI task;
- preserve warnings that EOL operating systems are compatibility targets, not
  secure production recommendations.

## Acceptance criteria

The project is complete when:

1. All three x86-64 artifacts are reproducibly built from one source revision
   and pass the shared functional contract.
2. Every supported platform collects and delivers the required metrics,
   services, and process snapshots with protocol v2 semantics.
3. The server accepts, validates, and stores `os_version` while remaining fully
   compatible with old agents.
4. Clean installation and migration preserve server identity, configuration,
   deliverable queued samples, and single-agent operation.
5. A forced failure at every destructive migration stage restores a working
   old agent, except that post-verification cleanup failure safely leaves only
   the native agent active.
6. Network outage, recovery, retries, duplicate delivery, credential failure,
   and permanent rejection behave as specified without data duplication or
   secret leakage.
7. Required automated checks and recorded platform smoke tests pass.
8. Project documentation describes only the implemented behavior and exact
   support boundaries.
