# Changelog

## Unreleased

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

## 0.4.13

- Added an optional "Remember me for 30 days" login mode. Remembered sessions
  bypass the normal 30-minute idle and 12-hour absolute timeouts until their
  fixed 30-day expiry, while logout still invalidates the session immediately.
- Production PHP sessions are now stored under the persistent `app_data` volume,
  so remembered logins survive MirvMon container and image restarts.
- Multi-architecture GHCR publication now builds amd64 and arm64 images on
  native GitHub-hosted runners, reuses per-architecture caches, and combines the
  resulting digests into the same user-facing multi-arch tags without QEMU.

## 0.4.12

- Server metric display settings now expose only dashboard-capable metrics from
  recent agent samples, hiding stale legacy names and internal helper values.
- Added paired disk I/O charts, grouped network choices, OS uptime history, and
  an independent stepped online/offline availability history with downtime and
  outage statistics.
- Linux agents now report the canonical `temp_system` temperature metric and
  ignore pseudo-filesystems such as tmpfs, procfs, sysfs, and cgroups when
  discovering filesystem-usage charts.
- Availability history is recorded independently from offline notification
  preferences, while `offline_timeout_seconds = 0` consistently disables
  offline transitions.

## 0.4.11

- Server liveness and offline/recovery notifications now use the MirvMon-side
  timestamp of the last authenticated agent contact instead of the agent's
  `sample_time`, so clock skew on a monitored host cannot cause false offline
  transitions.
- Metric timestamps remain agent-provided and continue to control metric history
  and current-value ordering independently from connectivity state.

## 0.3.5

- Multi-architecture GHCR publication now runs only after all release-tag CI
  jobs, including TimescaleDB tests, complete successfully.

## 0.3.4

- Corrected integration-test expectations for the agent token generation
  migration and PostgreSQL `COUNT(*)` result type.

## 0.3.3

- Installer credentials now reuse the active agent token. Only an explicitly
  confirmed administrator action revokes it and creates a replacement.

## 0.3.2

- Linux installer rotation: a newly downloaded installer atomically replaces
  an existing agent configuration with its new credential while preserving the
  persistent queue.

## 0.3.1

- Extended the x86-64 Linux agent to CPython 3.6–3.14 without a `requests`
  dependency.
- Added installer support for Debian 10+, Ubuntu 18+, CentOS/RHEL/Oracle Linux
  7+, AlmaLinux/Rocky Linux 8+, and MX Linux SysVinit installations.
- Preserved modern systemd operation, including systemd 219 compatibility, and
  preserved Windows agent installers and the Windows 7 legacy collector.
