# Native Windows Installer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans
> to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for
> tracking.

**Goal:** Replace the PowerShell installation transaction with a directly
invoked native Go transaction while retaining the unified personalized EXE.

**Architecture:** NSIS selects the compatible bundled agent and invokes
`install-windows` with non-secret release metadata. A portable Go transaction
core coordinates existing enrollment/migration packages through a Windows
platform adapter for OS, ACL, SCM, and scheduled-task operations.

**Tech Stack:** NSIS 3, Go 1.20.14/1.26.5, `golang.org/x/sys/windows`, PHP 8.5,
PHPUnit 13.

## Global Constraints

- Windows support remains x64 only and the existing supported OS matrix is
  unchanged.
- No PowerShell or BAT file/runtime invocation remains in the Windows package.
- Installer and permanent credentials never enter argv, compiler definitions,
  logs, or error strings.
- Validation and first migration precede existing-agent mutation.
- Migration is repeated after the old collector is stopped.
- Failures after commit restore files, service state, and legacy-task state.
- Every behavior change begins with a focused failing test.

---

### Task 1: Portable native transaction

**Files:**
- Create: `agent/internal/wininstall/install.go`
- Create: `agent/internal/wininstall/install_test.go`
- Create: `agent/internal/wininstall/platform.go`

**Interfaces:**
- Produces: `wininstall.Install(context.Context, Request) error`.
- Produces: `Platform` methods for prerequisite validation, ACLs, service
  snapshot/install/restore, and legacy-task freeze/delete/restore.
- Consumes: `enroll.Activate`, `migrate.Import`, `config.Load/WriteAtomic`, and
  `queue.Open`.

- [ ] Write a fake-platform transaction test that records `validate`, first
  migration, snapshot/freeze, second migration, file install, service start,
  verification, and task deletion in exact order.
- [ ] Run `(cd agent && go test ./internal/wininstall)` and verify RED because
  the package/API is absent.
- [ ] Implement request validation, self SHA-256/size/identity checks, private
  staging, activation, config/queue preflight, quiesced migration, commit, and
  cleanup using injected dependencies.
- [ ] Add failure-table tests at every commit step asserting rollback and no
  bootstrap/token text in returned errors.
- [ ] Run focused tests and commit with
  `feat(agent): add native Windows installation transaction`.

### Task 2: Windows platform adapter and CLI

**Files:**
- Create: `agent/internal/wininstall/platform_windows.go`
- Create: `agent/internal/wininstall/platform_other.go`
- Create: `agent/internal/wininstall/windows_version.go`
- Create: `agent/internal/wininstall/windows_version_test.go`
- Modify: `agent/cmd/mirvmon-agent/main.go`
- Modify: `agent/cmd/mirvmon-agent/main_test.go`

**Interfaces:**
- Produces CLI `install-windows --bootstrap --expected-version
  --expected-artifact --expected-sha256 --expected-size`.
- Windows adapter uses `RtlGetVersion`, `icacls.exe`, SCM APIs, and
  `schtasks.exe`; non-Windows returns `ErrUnsupportedPlatform`.

- [ ] Write RED version-matrix and CLI argument/secrecy tests.
- [ ] Implement native x64/elevation/version checks; require SP1 for kernel
  6.1, legacy for 6.1-6.3, and modern for 10.0+.
- [ ] Implement SCM snapshot/config/start/wait/restore with `x/sys/windows`.
- [ ] Implement task XML enabled-state parsing plus disable/end, delete, and
  enable/run/disable rollback for all enabled/running combinations.
- [ ] Wire the CLI with generic stderr and exact exit classes.
- [ ] Run Go tests, race tests, and both Windows amd64 cross-builds; commit with
  `feat(agent): expose native Windows installer command`.

### Task 3: Direct NSIS launch and package service

**Files:**
- Modify: `resources/agent/windows/installer.nsi`
- Delete: `resources/agent/windows/mirvmon-install.ps1`
- Modify: `src/Services/WindowsInstallerPackageService.php`
- Modify: `tests/Unit/Contracts/WindowsInstallerContractTest.php`
- Modify: `tests/Unit/Services/WindowsInstallerPackageServiceTest.php`

**Interfaces:**
- NSIS compiler defines: `EXPECTED_VERSION`, `MODERN_SHA256`, `MODERN_SIZE`,
  `LEGACY_SHA256`, `LEGACY_SIZE`.
- Package payload: two agent EXEs plus `bootstrap.json`, with no scripts.

- [ ] Write RED contracts requiring direct `install-windows` calls, WinVer/x64
  selection, `/OEM`, secret-free compiler args, and absence of PowerShell/BAT.
- [ ] Replace the PowerShell extraction/invocation with modern/legacy NSIS
  branches and exact non-zero propagation.
- [ ] Remove PowerShell rendering from the PHP package service and pass only
  validated public artifact metadata as compiler definitions.
- [ ] Run focused contracts and a real `makensis` fixture; commit with
  `fix(installer): launch native Windows transaction`.

### Task 4: Documentation and release verification

**Files:**
- Modify: `README.md`
- Modify: `INSTALL.md`
- Modify: `ARCHITECTURE.md`
- Modify: `TECHNICAL_SPECIFICATION.md`
- Modify: `AGENTS.md`

**Interfaces:**
- Documents NSIS-to-Go installation, unchanged OS matrix, rollback behavior,
  and real-host smoke requirements.

- [ ] Replace PowerShell 2.0 installer wording and package-content diagrams
  with the native command flow; retain Go 1.20.14 legacy build requirements.
- [ ] Run `composer test`, `composer analyse`, Composer validation/audit, Go
  tests/race/cross-builds, assets/deployment contracts, and `git diff --check`.
- [ ] Build the production image and compile a personalized EXE fixture.
- [ ] Review the diff for rollback gaps and secret leakage, then commit with
  `docs: describe native Windows installation`.
