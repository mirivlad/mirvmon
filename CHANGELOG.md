# Changelog

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
