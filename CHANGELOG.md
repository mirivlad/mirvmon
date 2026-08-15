# Changelog

Released sections below were reconstructed from the repository tag boundaries and
Git history. Until a release tag is created, current work stays under
`Unreleased`.

## Unreleased

- Added the administrator-only **System / MirvMon** diagnostics page with
  separate application and monitoring-host health instead of folding both into
  one status.
- Added PostgreSQL/TimescaleDB diagnostics with database availability, versions,
  database size and a lightweight connection check.
- Moved worker heartbeat visibility out of the notification queue and into system
  diagnostics, where missing and stale notification/offline workers are reported
  explicitly.
- Added notification pipeline diagnostics with per-status queue counts, stale
  processing leases, overdue ready jobs and the age of the oldest ready job.
- Added a configurable **MirvMon host** selected from ordinary monitored servers;
  CPU, RAM, uptime, load average and disk usage reuse the normal agent metrics
  without Docker socket access or extra container privileges.
- Added a compact MirvMon health indicator to the main dashboard and completed
  the remaining dashboard localization wiring for the production translator and
  the ungrouped-server label.
- Linux native agents now report load averages when `/proc/loadavg` is available;
  failure to read that optional source does not fail the complete measurement.
- Locked in the legacy recovery path from native agent `v0.4.12`: a stale pending
  four-component target is superseded after deploying `v0.4.16`, after which a
  fresh three-component `v0.4.16` update command can be issued to the old updater.

## 0.4.15.3

- Extended release-version parsing through the PHP artifact catalog, server-side
  version comparison and the native self-updater so four-component hotfix
  versions such as `v0.4.15.3` are accepted consistently at runtime.
- Restored agent configuration/server pages that had failed when a four-part
  release version was embedded into the native-agent artifact manifest.
- Improved production exception logging and made `APP_DEBUG` configurable from
  the deployment environment without editing the Compose file.

## 0.4.15.2

- Fixed the GHCR release workflow so four-component hotfix tags such as
  `v0.4.15.2` run the full release pipeline, publish amd64/arm64 images, update
  `latest`, and refresh the matching `0`, `0.4`, and `0.4.15` aliases.

## 0.4.15.1

- Restored the PHPStan iterable annotations that were lost when PHPDoc blocks
  were compacted during the localization refactor.
- Registered Twig translation functions before rendering instead of mutating
  Twig extensions from request middleware, and kept direct controller/test
  construction compatible with the default Russian translator.
- Updated localization contract tests and notification validation wiring so the
  full PHP, frontend, agent, amd64, and arm64 CI matrix is green again.

## 0.4.15

- Added a first-class localization layer for Twig, PHP and dynamic browser UI.
- MirvMon now ships Russian and English catalogs; Russian remains the default.
- Added a global interface-language selector under Settings, persisted in
  `app_settings`, with a safe fallback to Russian for missing locale settings.
- Localized the main navigation, authentication/setup, dashboard, servers,
  groups, alerts, agent-management, notification, queue and user-management
  screens together with interactive agent-update/dashboard text.
- Human-facing validation, administration feedback and HTML error pages use the
  selected language while API/protocol enum and error codes remain stable and
  language-neutral.
- Added localization contract tests that require exact key parity between all
  bundled Russian and English catalog fragments.

## 0.4.14

- Server list agent updates can now be started directly from the Agent column
  without opening each server detail page.
- Agent update state is refreshed in place while commands are pending, accepted,
  downloading, installing, restarting, or failed; the installed version updates
  without a page reload when the agent reports back.
- Added an Agent filter for all, current, and outdated installations. When the
  outdated filter is active, a server disappears from the list as soon as its
  agent reaches the current version.
- Update-state polling is batched across all agents currently being updated, so
  quickly triggering several updates does not require one polling request per
  server.
- Server platform/status icons now use a white glyph on a solid status-colored
  circle, making online, warning, critical, and no-data states easier to tell
  apart everywhere the shared status icon is used.
- The server overview only renders the temperature panel when at least one
  temperature metric is selected; selected temperature metrics with no samples
  still show an explicit no-data message.
- Reconstructed the historical changelog from the repository tag boundaries and
  Git history, and added the release-oriented roadmap.

## 0.4.13

- Added an optional "Remember me for 30 days" login mode. Remembered sessions
  bypass the normal 30-minute idle and 12-hour absolute timeouts until their
  fixed 30-day expiry, while logout still invalidates the session immediately.
- Production PHP sessions are stored under the persistent `app_data` volume, so
  remembered logins survive MirvMon container and image restarts.
- Multi-architecture GHCR publication builds amd64 and arm64 images on native
  GitHub-hosted runners, reuses per-architecture caches, and combines the
  resulting digests into the same user-facing multi-arch tags without QEMU.

## 0.4.12

- Server metric display settings expose only dashboard-capable metrics from
  recent agent samples, hiding stale legacy names and internal helper values.
- Added paired disk I/O charts, grouped network choices, OS uptime history, and
  an independent stepped online/offline availability history with downtime and
  outage statistics.
- Linux agents report the canonical `temp_system` temperature metric and ignore
  pseudo-filesystems such as tmpfs, procfs, sysfs, and cgroups when discovering
  filesystem-usage charts.
- Availability history is recorded independently from offline notification
  preferences, while `offline_timeout_seconds = 0` consistently disables
  offline transitions.

## 0.4.11

- Server liveness and offline/recovery notifications use the MirvMon-side
  timestamp of the last authenticated agent contact instead of the agent's
  `sample_time`, so clock skew on a monitored host cannot cause false offline
  transitions.
- Metric timestamps remain agent-provided and continue to control metric history
  and current-value ordering independently from connectivity state.

## 0.4.10

- Embedded fallback CA roots for agent HTTPS and legacy bootstrap paths so old
  monitored hosts with stale system CA stores can still verify modern TLS
  certificates.
- System/private trust roots remain usable; certificate verification is not
  disabled by the fallback bundle.

## 0.4.9

- Restored the SysV/LSB Linux installer path for supported hosts where systemd is
  not PID 1.
- Kept native-agent self-update working on SysV systems through a root-owned
  privileged updater instead of requiring a systemd service.

## 0.4.8

- Made stale self-update recovery explicit instead of silently replacing an
  obsolete command that a legacy agent may already have accepted locally.
- Obsolete pending update commands are terminalized and administrators can retry
  after clearing stale local update state; the recovery procedure is documented.

## 0.4.7

- Fixed administrator/elevation detection in the Windows installer on legacy
  CLR/Windows combinations by using process-token APIs available on those hosts.

## 0.4.6

- Added the native Windows installation transaction used by the personalized
  installer: one-time activation, native installer command execution, rollback
  boundaries, and protected embedded payload handling.
- Update reconciliation now completes a command when the reporting agent is on
  the requested version or a newer compatible version with the expected
  artifact, preventing superseded targets from remaining stuck.

## 0.4.5

- Replaced separate Windows installation flows with one personalized x64 EXE
  installer built with NSIS for supported modern and legacy Windows targets.
- Hardened the transactional handoff between legacy scheduled-task agents and
  native services, including rollback, cleanup, and CLR 2 compatible paths.

## 0.4.4

- Added a server-generated offline ZIP installation package for legacy Windows
  machines so the complete bootstrap payload can be transferred as one package.
- The final legacy migration now stops/freezes the old scheduled task and
  re-imports its quiescent queue immediately before the native handoff, avoiding
  samples being appended between migration and cutover.

## 0.4.3

- Added end-to-end native agent self-update: agent identity/capabilities,
  persistent update commands, authenticated artifact delivery, progress states,
  server UI controls, privileged apply helpers, restart verification, and
  rollback on failure.
- Hardened the update handoff so privileged apply is explicitly authorized and
  temporary request/marker files are cleaned up on failed handoff publication.
- Unified server operating-system/status icon presentation and updated the
  FrankenPHP runtime dependency.

## 0.4.2

- Hardened migration from legacy agents into the native runtime: preserve the
  server-provided permanent queue path, accept legacy `monitor_services: null`,
  normalize it on write, and retain unknown compatible configuration values.
- Linux installation removes only known obsolete Python runtime files after a
  successful switch instead of deleting valid native state.

## 0.4.1

- Added a searchable and sortable server list and moved agent management onto the
  server detail page, with consistent icon-only action controls.
- Moved notification-outbox administration to a dedicated page with filtering,
  per-job retry/delete actions, and bulk queue operations.
- Fixed individual outbox retry/delete SQL so the selected notification job is
  actually acted on.

## 0.4.0

- Replaced the Python/PowerShell primary agent implementation with native Go x64
  agents: Go 1.26.5 for modern Linux/Windows and Go 1.20.14 for Windows 7 SP1 and
  Windows Server 2008 R2.
- Added the native protocol/configuration core, durable bounded delivery queue,
  Linux and Windows host collectors, native service runtime, operating-system
  reporting, and validated artifact manifests.
- Installers migrate legacy Python/PowerShell configuration and queued samples
  before switching to native services, with failure-safe state handling.
- CI now tests and cross-compiles the Go agent variants instead of the former
  Python matrix.

## 0.3.7

- Explicit credential rotation/reinstallation now stops the old agent and clears
  queued samples signed with the revoked credential, preventing an undeliverable
  stale queue from surviving the switch on Linux and Windows.

## 0.3.6

- Legacy agent credentials now require explicit administrator rotation before
  issuing replacement installers; installer downloads no longer rotate an active
  legacy credential implicitly.
- Release builds embed `APP_VERSION` and expose it in the UI footer.
- Tag-triggered multi-arch publication was folded into the main CI workflow so
  the same release pipeline runs checks and publishes the image.

## 0.3.5

- Multi-architecture GHCR publication runs only after all release-tag CI jobs,
  including TimescaleDB tests, complete successfully.

## 0.3.4

- Corrected integration-test expectations for the agent token generation
  migration and PostgreSQL `COUNT(*)` result type.

## 0.3.3

- Installer credentials reuse the active agent token. Only an explicitly
  confirmed administrator action revokes it and creates a replacement.

## 0.3.2

- Linux installer rotation atomically replaces an existing agent configuration
  with the newly issued credential while preserving its persistent queue.
## 0.3.1

- Extended the x86-64 Linux Python agent to CPython 3.6–3.14 without a
  `requests` dependency.
- Added installer support for Debian 10+, Ubuntu 18+, CentOS/RHEL/Oracle Linux
  7+, AlmaLinux/Rocky Linux 8+, and MX Linux SysVinit installations.
- Preserved modern systemd operation, including systemd 219 compatibility, and
  preserved Windows agent installers and the Windows 7 legacy collector.

## 0.3.0

- Threshold notifications can include a generated PNG chart of the affected
  metric for the preceding hour, with the threshold drawn on the chart.
- Telegram sends the graph as a photo and email embeds it inline; rendering
  failures or non-metric events safely fall back to text-only notifications.
- Added GD/font runtime support so Cyrillic server names render correctly in
  notification charts.

## 0.2.5

- Agents report their version with each sample and MirvMon stores/displays the
  most recently reported version in the server list.
- The field remains backward-compatible: old agents can omit it without clearing
  a previously known version; the legacy Windows collector identifies itself
  separately.

## 0.2.4

- Added optional per-event notification cooldown to suppress repeated copies of
  the same alert while a metric remains over threshold.
- Recovery notifications use a distinct event type and are never swallowed by
  the cooldown; a zero cooldown preserves the previous behavior.

## 0.2.3

- Added per-server maintenance windows from 30 minutes to 24 hours with an
  optional reason.
- Alerts continue to be evaluated and recorded during maintenance, but delivery
  is suppressed until the window expires or is ended manually.

## 0.2.2

- Added per-server Telegram chat and email recipient overrides while retaining
  installation-wide defaults when no override is configured.
- Global email configuration now supports multiple recipients.
- Each destination is persisted as its own outbox job so one failing recipient
  cannot block the others and queued delivery does not change when settings are
  edited later.

## 0.2.1

- Added worker heartbeats and UI visibility for workers that have stopped
  polling, making a stalled notification pipeline distinguishable from an idle
  one.
- Added configurable retention/purging of delivered and dead outbox jobs while
  never purging notifications still waiting for delivery.

## 0.2.0

- Added a self-contained legacy agent for x64 Windows 7 SP1 and Windows Server
  2008 R2, where the contemporary Python runtime cannot be used.
- The collector uses PowerShell 2.0-compatible WMI, hand-built envelope-v2 JSON,
  `HttpWebRequest`, numeric TLS 1.2 selection, `schtasks`, and a bounded local
  retry queue.
- Service names rejected by the ingestion contract are skipped instead of
  causing the complete metrics envelope to fail.

## 0.1.6

- Added `recovery_duration_seconds`: an active threshold alert is resolved only
  after the metric remains below the configured threshold for the full recovery
  window, reducing alert/recovery flapping.
- Recovery duration can be configured per metric/server and as a global default.

## 0.1.5

- Manual alert resolution now queues an `alert_resolved` notification identifying
  the operator who resolved it.
- Server-specific notifications can include a direct link back to the MirvMon
  server page when `PUBLIC_BASE_URL` is configured.

## 0.1.4

- Network throughput is formatted consistently in readable B/s, KB/s, MB/s,
  GB/s, or TB/s units across charts, tooltips, dashboard tiles, and interface
  summaries.
- Environment templates clarify that SMTP and Telegram settings live encrypted
  in the database rather than in environment variables.

## 0.1.3

- Added an image-level `/readyz` Docker healthcheck, replacing the inherited
  FrankenPHP/Caddy admin-port probe that incorrectly marked containers unhealthy.
- Added `host.docker.internal` via `host-gateway` so a container can reach a
  Telegram proxy running on the Docker host, and updated the UI hint accordingly.

## 0.1.2

- Fixed notification retry accounting so jobs exhaust the configured retry
  budget correctly instead of entering dead-letter state after a string
  comparison error.
- Preserved sanitized transport failure reasons, surfaced Telegram/cURL details,
  and treat delivery through a disabled channel as an explicit dead job rather
  than a successful send.
- Added recent outbox status/error visibility and retry controls to notification
  administration.

## 0.1.1

- Fixed saving notification settings when Telegram/email switches are disabled by
  explicitly binding PostgreSQL boolean values instead of passing empty strings.

## 0.1.0

- First tagged public release of MirvMon after the platform redesign around PHP,
  Slim/Twig, PostgreSQL 17 + TimescaleDB, and a hardened two-container
  FrankenPHP production runtime.
- Added push metric ingestion, server/group management, metric history and
  dashboard/server-detail visualizations with a compact current-value read model.
- Added encrypted asynchronous Telegram/SMTP notification settings and outbox
  workers, then hardened the production runtime and automation around them.
- Redesigned the dashboard, self-hosted frontend assets, and published the project
  under the MIT license with installation documentation and a screenshot gallery.