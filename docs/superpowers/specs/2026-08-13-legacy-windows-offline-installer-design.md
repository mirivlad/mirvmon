# Legacy Windows Offline Installer Design

## Scope

MirvMon will replace the broken network-bootstrap installer for Windows 7 SP1
x64 and Windows Server 2008 R2 SP1 x64 with a self-contained, auditable ZIP
package. The target runtime is Russian Windows Server 2008 R2 SP1 x64 with
PowerShell 2.0 and CLR 2.0.50727. Windows Server 2008 without R2 and all 32-bit
systems remain unsupported.

The change is limited to legacy Windows distribution and installation. Agent
metrics, authentication, configuration, queue formats, server API semantics,
and the modern Windows installer remain unchanged.

## Root Causes

The current legacy installer is not actually independent of the legacy
operating system's TLS stack. It downloads the native EXE through
`Net.WebClient`; CLR 2.0 cannot enable TLS 1.2 through the numeric
`SecurityProtocolType` value used by the script. The installer also passes the
localized account names `SYSTEM` and `Administrators` to `icacls`, hides native
program failures behind `Out-Null`, and embeds its PowerShell body in a large
BAT `-EncodedCommand` argument.

The current switching order is unsafe because it stops and removes the old
runtime before all new-state and service checks have succeeded.

## Distribution Format

The canonical endpoint is:

```text
GET /agent/install-legacy.zip?token=<one-time installer credential>
```

It returns:

```text
mirvmon-agent-windows-legacy-amd64/
  install.bat
  mirvmon-install-legacy.ps1
  mirvmon-agent.exe
  server-config.json
```

The server builds the ZIP from repository-owned BAT/PS1 templates, the
checksum-verified `windows-legacy-amd64` artifact, catalog version/checksum
metadata, and the exchanged permanent agent credential. `server-config.json`
is encoded as ASCII without a BOM. The response retains the current secret
installer response protections (`no-store`, no referrer, and attachment
disposition).

The existing `/agent/install-legacy.bat` and `/agent/install-legacy.ps1`
routes remain accepted aliases and return the same ZIP response. The UI issues
one legacy package credential and links only to the canonical ZIP endpoint.
This preserves old installer links while removing the impossible promise that
a single BAT or PS1 response is self-contained.

Package generation uses PHP `ZipArchive`. `ext-zip` becomes an explicit
Composer, CI, container, and startup requirement.

## Legacy Toolchain

The modern agent remains on Go 1.26.5. The legacy Windows artifact remains a
separate deterministic `CGO_ENABLED=0`, `GOOS=windows`, `GOARCH=amd64`,
`GOAMD64=v1` build using Go 1.20.14. Go 1.20 is the final Go release line that
supports Windows 7 and Windows Server 2008 R2; Go 1.21 requires Windows 10 or
Windows Server 2016.

CI continues to test the module on both toolchains and cross-compile Windows
amd64. Release-package tests additionally prove that the catalog's verified
legacy EXE is the exact EXE placed in the ZIP. Static PE inspection and a
successful cross-build are build evidence only; real Server 2008 R2 execution
must remain explicitly unconfirmed until tested on the user's target host.

## BAT Bootstrap

`install.bat` is short ASCII text. It:

1. determines its own directory with `%~dp0`;
2. checks Administrator privileges using a local administrative operation;
3. confirms that the adjacent PS1 exists;
4. invokes `powershell.exe -NoLogo -NoProfile -NonInteractive
   -ExecutionPolicy Bypass -File "...\mirvmon-install-legacy.ps1"`;
5. returns the exact PowerShell exit code.

It contains no encoded command, generated PowerShell, Base64 payload, network
command, or secret.

## PowerShell 2.0 Boundary

The PS1 obtains its directory from `$MyInvocation.MyCommand.Path` and
`Split-Path`. It uses only syntax, cmdlets, .NET APIs, and command options
available with PowerShell 2.0 and CLR 2.0. It does not use `$PSScriptRoot`,
advanced class/enum syntax, splatting features introduced after PS2, CIM
cmdlets, modern JSON cmdlets, or modern web cmdlets.

The installer never performs a network request. It reads the adjacent EXE and
server config. SHA-256 is calculated using
`System.Security.Cryptography.SHA256Managed` and a local file stream.

External executables run through a small PS2-compatible helper that captures
their output and `$LASTEXITCODE`. Critical failures include the stage name,
program name, and exit code without printing the permanent token or config
contents. Expected absence or already-stopped cases are discovered before the
operation or explicitly allowlisted; arbitrary non-zero results are not
suppressed.

## Locale-Independent ACLs

Every legacy `icacls` invocation uses numeric well-known SIDs with the leading
asterisk required by `icacls`:

```text
*S-1-5-18
*S-1-5-32-544
```

Directories grant inheritable full control to LocalSystem and the built-in
Administrators group. Config, queue, staged files, rollback files, and the
installed binary receive explicit protected ACLs. Every ACL command checks its
exit code. No localized account name is used.

## Installation Transaction

### Prepare and validate

Before changing the running installation, the PS1:

1. verifies Administrator privileges, x64 OS, and all adjacent package files;
2. verifies the bundled EXE size and SHA-256 against rendered catalog values;
3. runs `version` and requires the expected release and
   `windows-legacy-amd64` artifact identity;
4. locates `%ProgramData%\MirvMon\Agent\config.json`, `queue.json`, and the
   older `queue.txt` fallback;
5. creates a protected staging directory;
6. runs native `check` using the new server configuration;
7. runs native `migrate` into staged config and queue files;
8. runs `check` against the staged final configuration.

Until all these steps pass, the existing service, scheduled task, config,
queue, and runtime files are not stopped, deleted, or modified.

### Commit

Immediately before switching, the installer records existing service metadata
through WMI and whether the legacy scheduled task exists. It creates timestamped
persistent backups of the old config/queue and transaction-local rollback
copies of every destination that may be replaced.

It then stops the detected old runtime, installs the validated binary/config/
queue, applies protected SID-based ACLs, and creates or reconfigures the quoted
`MirvMonAgent` LocalSystem service. Existing service metadata is retained for
rollback rather than deleting the service first. Paths containing spaces are
always quoted in the SCM `binPath`.

An enabled legacy scheduled task is disabled before it is stopped, so it cannot
start again during the switch. After the old runtime is quiescent, migration
and staged-config validation run once more to capture queue entries written
since preflight; only that refreshed state is copied to the final paths.

### Start, verify, and cleanup

The installer starts `MirvMonAgent` and polls service state through WMI until it
is `Running` or a bounded timeout expires. Only after successful verification
does it delete the old scheduled task and remove transaction staging. The
timestamped config/queue backups remain available to the administrator.

### Rollback

Any failure after commit begins stops the new service, restores or removes each
destination according to the transaction journal, restores existing service
configuration or removes a newly created service, and restarts the previously
running service or scheduled task. Rollback errors are reported separately and
never replace the original stage/exit-code error.

This ordering prevents failed `check` or `migrate` from touching the old agent,
prevents two collectors from running concurrently, and makes post-switch
failure recoverable.

## Idempotency and State Migration

Clean installation uses the bundled server config and creates an empty bounded
queue. Repeated installation treats the current native config/queue as migration
input and preserves user-configurable settings. Python JSON queues and the
PowerShell line queue remain supported by the existing native `migrate`
command. A repeated successful run replaces only known MirvMon destinations
and creates a new timestamped backup; it does not delete unrelated legacy
runtime files until the new service is verified.

## Testing

PHP unit and integration tests cover ZIP structure, exact artifact bytes,
one-time credential consumption, ASCII/no-BOM JSON, secret response headers,
canonical and alias endpoints, and UI links.

Installer contract tests cover the absence of network bootstrap,
`EncodedCommand`, `$PSScriptRoot`, and localized account names; the required
SID form; explicit native exit-code handling; quoted service paths; paths with
spaces; validation/migration before the first destructive action; rollback
registration; clean and repeated-install branches; Python/PowerShell state
migration; and package completeness.

Go tests confirm that generated server config is accepted and migration retains
supported config/queue semantics. CI runs Go 1.20.14 tests/race checks and an
amd64 Windows legacy cross-build. A Windows Server 2008 R2 SP1 x64 manual smoke
test remains the final runtime acceptance step.

## Documentation and Operator Handoff

README, installation, architecture, and technical specification documents will
describe ZIP transfer/extraction, Administrator execution, expected stages,
backup locations, rollback behavior, toolchain boundary, and exact manual test
commands. The final report will distinguish automated verification from the
unconfirmed target-OS runtime test and name the exact ZIP artifact to download.
