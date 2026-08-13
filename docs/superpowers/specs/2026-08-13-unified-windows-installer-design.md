# Unified Windows Installer Design

## Scope

MirvMon will expose one Windows x64 installer, `MirvMon-Agent-Setup.exe`, for
every supported Windows release. The server UI will contain exactly two agent
download actions: Linux and Windows. The Windows installer is intentionally
unsigned; no code-signing certificate or private key is stored in the image or
production container.

Supported Windows systems are:

- Windows 7 SP1, 8, 8.1, 10, and 11 x64;
- Windows Server 2008 R2 SP1, 2012, 2012 R2, 2016, 2019, 2022, and 2025 x64.

Windows x86/ARM, Windows Vista, Windows Server 2008 without R2, and Windows 7
or Server 2008 R2 without SP1 are rejected before the existing agent is
changed.

## Distribution and credentials

The canonical download is:

```text
GET /agent/install.exe?token=<installer credential>
```

Downloading validates but does not consume the installer credential. The
server runs `makensis` against a repository-owned script and a private,
randomly named payload directory. The generated EXE embeds:

- the current `windows-amd64` agent built with the modern Go toolchain;
- `windows-legacy-amd64` built from the same sources with Go 1.20.14;
- a PowerShell 2.0-compatible transactional installer;
- the release version, sizes, and SHA-256 checksums for both agents;
- the public service URL and the short-lived installer credential.

The permanent agent token is not embedded. During installation the selected
Go agent sends the installer credential in an HTTPS Authorization header to:

```text
POST /api/v1/agent/install
```

The server atomically consumes the credential and returns the complete native
agent configuration with `Cache-Control: no-store`. Failed, expired, reused,
or malformed credentials receive a generic `invalid_token` response. Tokens
and generated configuration are never logged or placed in child-process
arguments. A failed installation can be retried by downloading a newly issued
installer; exchanging a credential does not rotate the existing permanent
agent generation.

## Package compiler boundary

`makensis` is a production runtime dependency installed in the application
image. PHP invokes it without a shell and passes only non-secret random paths
through compiler defines. The installer credential exists only in a mode-0600
payload file inside a mode-0700 temporary directory. The compiler output is
bounded, checked for a successful exit and an `MZ` executable header, read into
the response, and removed with the whole payload directory on every path.

Concurrent requests use independent directories. The response is an
attachment named `MirvMon-Agent-Setup.exe`, with `no-store`, `no-referrer`, and
`nosniff` headers.

## Target selection

The shared PowerShell installer obtains `Win32_OperatingSystem` through WMI
and verifies an AMD64 operating system. Kernel versions 6.1, 6.2, and 6.3 use
`windows-legacy-amd64`; version 6.1 additionally requires Service Pack 1.
Kernel version 10.0 and later uses `windows-amd64`. Unsupported versions stop
before credential exchange, migration, service changes, or file replacement.

The selected binary is checked against the rendered size and SHA-256 and its
`version` output must identify the expected release, `windows/amd64`, and
artifact key. The unselected binary is never executed.

## Activation command

Both agent builds expose the same command:

```text
mirvmon-agent activate --bootstrap <path> --output-config <path>
```

The bootstrap JSON accepts only `base_url` and `installer_credential`. The
command builds the fixed activation URL, performs a bounded POST using Go's
TLS implementation, validates the returned configuration through the shared
config package, and atomically writes a mode-0600 output. Errors are generic
and never include the URL query, credential, permanent token, or response
body. The command is unavailable on non-Windows builds except for portable
unit-testable enrollment logic.

## Transactional installation

After OS and binary validation, the existing PowerShell 2.0 transaction is
retained and generalized for both artifacts:

1. protect the temporary directory with numeric well-known SIDs;
2. activate into a staged server config;
3. run native `check` and `migrate` into staged config and queue files;
4. run `check` against the staged final configuration;
5. record the existing service/task state and rollback copies;
6. stop the previous service or scheduled task;
7. repeat migration after the old collector is quiescent;
8. install the selected binary, config, and queue with protected ACLs;
9. create or reconfigure the `MirvMonAgent` LocalSystem service;
10. start it, wait for `Running`, then remove the obsolete scheduled task.

Any failure after commit begins restores the previous files and service/task
state. `queue.json` and the PowerShell `queue.txt` fallback remain supported.
All service paths are quoted, all critical native exit codes are checked, and
ACLs use `*S-1-5-18` and `*S-1-5-32-544` rather than localized names.

## HTTP and UI cleanup

The old `/agent/install.ps1`, `/agent/install.bat`,
`/agent/install-legacy.zip`, `/agent/install-legacy.ps1`, and
`/agent/install-legacy.bat` routes are removed. There are no released
production installations whose stale installer links must be retained, and
returning an EXE from a script-named route would be misleading.

Server creation and the Agent tab issue exactly two independent installer
credentials and render two buttons:

- Linux x64 (`/agent/install.sh`);
- Windows x64 (`/agent/install.exe`).

Windows help text names the complete supported matrix, Administrator launch,
automatic artifact selection, migration, and the expected unsigned-publisher
warning.

## Build and verification

The image installs `nsis`; startup checks that `makensis` is executable. The
obsolete ZIP package service and its `ext-zip`-only dependency are removed if
no other runtime code uses them. CI tests both Go toolchains, cross-compiles
both Windows artifacts, compiles a real NSIS fixture, checks the two-button UI,
and builds the production image.

Automated tests cover credential validation versus consumption, activation
response secrecy, bounded Go enrollment, OS-to-artifact selection contracts,
package contents/metadata, compiler failure and cleanup, migration/rollback
ordering, removed routes, and exact UI link count. Real Windows smoke tests
remain required for Windows 7 SP1/Server 2008 R2 SP1 and one modern Windows
host because Linux cross-build and static NSIS inspection cannot prove SCM,
WMI, UAC, or PowerShell runtime behavior.
