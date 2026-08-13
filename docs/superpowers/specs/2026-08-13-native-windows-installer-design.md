# Native Windows Installer Design

## Scope

The personalized `MirvMon-Agent-Setup.exe` remains the only Windows download,
but its runtime path becomes entirely native: NSIS selects one bundled Go
agent and invokes its `install-windows` command directly. The package contains
no PowerShell or BAT file and does not depend on either interpreter.

The supported matrix does not change: Windows 7 SP1, 8, 8.1, 10, 11 and
Windows Server 2008 R2 SP1, 2012, 2012 R2, 2016, 2019, 2022, 2025, all x64.
Windows 6.1 without SP1 and unsupported/x86 systems fail before installer
credential exchange or existing-agent mutation.

## NSIS boundary

NSIS embeds `mirvmon-agent-modern.exe`, `mirvmon-agent-legacy.exe`, and the
protected `bootstrap.json`. `WinVer.nsh` and `x64.nsh` select the modern binary
for kernel 10.0+ and the legacy binary for kernels 6.1-6.3. The selected agent
receives only non-secret expected release metadata plus the bootstrap path:

```text
install-windows --bootstrap <path> --expected-version <version>
  --expected-artifact <artifact> --expected-sha256 <sha256>
  --expected-size <bytes>
```

The one-time credential remains only in `bootstrap.json`; it never appears in
the process command line or NSIS compiler arguments. NSIS streams native-agent
output using OEM conversion, propagates a non-zero exit, and reports no false
success.

## Native transaction

`agent/internal/wininstall` owns a testable transaction core. A Windows
platform adapter supplies elevation/OS checks, ACL commands, SCM operations,
and the obsolete scheduled-task lifecycle. The core uses the existing
`enroll`, `migrate`, `config`, and `queue` packages directly rather than
starting child copies of the agent.

The transaction validates the running installer binary's size, SHA-256,
release version, artifact identity, x64 target, and supported Windows release
before activation. It then activates into a private stage, validates and
migrates existing `config.json` plus `queue.json` or `queue.txt`, snapshots the
old service/files/task, freezes the old collector, repeats migration against
the quiesced queue, installs protected files, configures the LocalSystem
`MirvMonAgent` service, starts and verifies it, then removes the obsolete task.

After the commit boundary, failure restores prior files, service configuration
and running state, and scheduled-task enabled/running state. Errors and logs
contain stage codes but never bootstrap contents, installer credentials,
permanent tokens, or server response bodies.

## Packaging and verification

`WindowsInstallerPackageService` no longer renders or packages a `.ps1` file.
It passes only validated version/checksum/size metadata to `makensis`; the
credential stays in the mode-0600 bootstrap payload. Contract tests require
the absence of `.ps1`, `.bat`, `powershell.exe`, and `cmd.exe` from the NSIS
template and package fixture.

Portable transaction tests use a fake Windows platform to cover ordering,
preflight safety, queue fallback, second migration, rollback, and secret-free
failures. Windows-specific code is cross-built with both the current Go
toolchain and Go 1.20.14. A real smoke test is still required on one legacy and
one modern x64 Windows host because Linux cannot exercise UAC, SCM, Task
Scheduler, or Windows ACL behavior.
