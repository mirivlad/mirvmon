# Agent Self-Update and Server Icons Design

## Scope

MirvMon will add administrator-triggered self-update support to the native x64
agent and will fix GitHub issue #4 by using one OS/status icon model across the
server list, dashboard, and server detail page.

The first capable release is `v0.4.3`. Agents older than `v0.4.3`, including
`v0.4.2`, cannot execute an update command and must be upgraded once with a new
installer. The first fully remote transition is therefore `v0.4.3` to a later
release.

Supported update targets remain:

- Linux x64;
- Windows 10 and Windows Server 2012 R2 or newer, x64;
- Windows 7 SP1 and Windows Server 2008 R2 SP1, x64, using the legacy build.

Windows Server 2008 without R2 and all 32-bit systems remain unsupported.

## Decisions

### Pull, not inbound control

The monitoring server never opens a connection to an agent. An administrator
creates an update command in the UI, and the agent receives it through its
existing outbound authenticated configuration request.

### Artifact update, not installer replay

An update downloads the matching native binary artifact rather than replaying
an installer containing configuration and credentials. Configuration, token,
queue, and service identity remain unchanged.

The command cannot contain a shell command or arbitrary URL. It identifies a
target release and command UUID. The agent derives the fixed artifact endpoint
from its configured server origin and its build-time artifact key.

### Explicit capability and artifact identity

Every capable agent reports:

- `agent_version`, already present;
- `agent_artifact`: `linux-amd64`, `windows-amd64`, or
  `windows-legacy-amd64`;
- `agent_capabilities`: initially `["self_update_v1"]`.

The server stores these values with the server read model. It offers a remote
update only when the current version is valid semver, the available artifact
version is newer, the artifact exists, and `self_update_v1` is present.

Development, CI, missing, malformed, equal, and newer agent versions are never
offered an update. Downgrades are forbidden.

## Server-Side Model

Migration `015_agent_self_updates.sql` adds artifact/capability columns to
`servers` and creates `agent_update_commands`.

Only one non-terminal command may exist per server. A command records its UUID,
server, target version, target artifact, state, requesting user, timestamps,
and a bounded public error code. It never stores a token, installer URL, raw
exception, or shell text.

States are:

1. `pending` — created by an administrator;
2. `accepted` — received and durably recorded by the agent;
3. `downloading` — artifact transfer started;
4. `installing` — platform updater took ownership;
5. `awaiting_restart` — replacement completed and restart was requested;
6. `succeeded` — a later metrics envelope reports the target version;
7. `failed` — validation, download, replacement, restart, or rollback failed.

The configuration endpoint returns a typed optional `update_command` object.
An authenticated status endpoint accepts monotonic progress for that command.
Ownership is always derived from the bearer token, never from a supplied server
ID. Only the metrics ingestion path can mark a command `succeeded`.

The administrator endpoint is a CSRF-protected POST and requires the admin
role. A retry creates a new UUID after the previous command becomes terminal.

## Artifact Catalog

The build manifest gains a top-level release version and retains the filename,
SHA-256 checksum, and content type for each artifact. `AgentArtifactCatalog`
validates the complete manifest and exposes the available release version.

Artifacts remain non-secret. The update command is authenticated; downloads
use the existing public same-origin binary endpoint over verified TLS. The
agent rejects cross-origin redirects, unexpected content length, oversized
payloads, checksum mismatch, unknown artifact keys, and a target version that
does not exactly match the command.

## Agent Update State Machine

The agent stores update progress atomically beside its queue. A repeated command
UUID resumes or reports the existing state and never starts a second install.
A different command is ignored while a non-terminal local update exists.

The main agent performs command validation and download as its unprivileged
service identity where applicable. It writes a staged executable, verifies its
SHA-256 checksum, verifies the staged binary reports the expected version and
artifact key, then hands control to the platform updater.

No token, authorization header, configuration body, local path supplied by the
server, or raw command payload is logged or returned to the UI.

## Linux Application and Rollback

The Linux service continues to run as `mirvmon-agent`. The `v0.4.3` installer
adds a root-owned systemd path unit and one-shot updater unit compatible with
systemd 219.

The agent writes a fixed-schema request in `/var/lib/mirvmon-agent`. The path
unit invokes the installed binary as root in `apply-update` mode. This mode:

1. revalidates fixed paths, version, artifact key, staged file ownership,
   executable mode, size, and checksum;
2. saves the current binary as a single rollback copy;
3. atomically replaces `/opt/mirvmon-agent/mirvmon-agent`;
4. restarts `mirvmon-agent.service`;
5. waits for a health record from the target version;
6. restores the previous binary and restarts it if validation or startup fails.

Config and queue files are never replaced during an update.

## Windows Application and Rollback

The Windows service already runs as `LocalSystem`. Because Windows locks a
running executable, the agent copies its trusted current binary to a protected
temporary updater path, starts that copy in `apply-update` mode, and exits.

The updater waits for the service process to stop, backs up the installed EXE,
replaces it with the verified staged EXE, starts `MirvMonAgent`, and waits for
the target health record. On failure it stops the new service, restores the
previous EXE, and starts the previous version.

The same protocol is compiled with Go 1.20.14 for Windows 7 SP1 and Server 2008
R2 SP1. It uses no PowerShell requirement and no API unavailable on those
systems.

## UI

The Agent tab shows installed version, available version, compatibility, and
the latest command state. Administrators see `Обновить` only for a compatible
newer artifact. Older agents show `Требуется ручное обновление`. Failed commands
show a bounded Russian error and allow a retry.

The server list shows a small update indicator beside the agent version and
links it to the Agent tab. Non-admin users may see state but cannot create a
command.

Issue #4 is fixed with a shared server icon partial and one platform classifier.
The platform is selected from `agent_artifact` when available, then from a
case-insensitive OS-version classifier for existing agents. Windows uses the
Windows icon, recognised Linux distributions use the Linux icon, and absent or
unrecognised data uses the generic server icon.

On the dashboard and detail page the selected OS/server icon is coloured by
`online`, `warning`, `critical`, or `offline`. Dashboard refresh changes only
the status class and never replaces the OS family icon.

## Failure Behaviour

- Offline agents leave commands pending until they poll again.
- Authentication failure prevents command delivery.
- Download or checksum failure leaves the installed binary untouched.
- Startup failure attempts rollback before reporting failure.
- A failure remains terminal until an administrator explicitly retries.
- A server update never automatically updates all agents.
- Token revocation invalidates update polling and progress reporting exactly as
  it invalidates configuration polling and metrics.

## Testing

Go unit tests cover command decoding, same-origin download, size and checksum
validation, durable idempotency, staged-binary inspection, Linux replacement,
Windows helper handoff, restart confirmation, and rollback. Platform effects
use injected filesystem/process/service boundaries. The agent suite runs with
the race detector and cross-compiles modern and legacy targets.

PHP unit/integration tests cover manifest version validation, strict semver,
capability/artifact persistence, command authorization and CSRF, ownership,
monotonic transitions, retry, completion from metrics, and UI view models.

Installer tests assert Linux unit permissions and Windows protected paths.
Functional UI tests cover update states and the icon classifier. Browser smoke
tests cover desktop and 390 px without console errors.

The final verification includes all required Composer, npm, Go, shellcheck,
Compose, clean two-container health, and Docker image checks.

## Documentation and Release

`README.md`, `INSTALL.md`, `ARCHITECTURE.md`, and
`TECHNICAL_SPECIFICATION.md` document the protocol, security boundary, manual
bootstrap requirement, platform behaviour, rollback, and operator workflow.

Work is split into reviewable commits. After full verification it is merged to
`master`, released as `v0.4.3`, and its GitHub Actions/image publication is
checked according to the release workflow.
