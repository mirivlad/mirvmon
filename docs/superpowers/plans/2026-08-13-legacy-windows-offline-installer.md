# Legacy Windows Offline Installer Implementation Plan

> **Execution requirement:** Use the test-driven-development and
> verification-before-completion skills for every implementation task. Work in
> the isolated `codex/legacy-windows-package` branch and keep changes scoped to
> legacy Windows distribution.

**Goal:** Ship a self-contained, transactional, PowerShell 2.0-compatible
legacy Windows x64 installer package that does not depend on the target host's
network/TLS stack.

**Architecture:** The server exchanges the one-time installer credential,
renders an ASCII server config, and packages it with repository-owned BAT/PS1
templates and the catalog-verified Go 1.20.14 EXE. The PS1 validates and
migrates entirely before stopping the old runtime, then commits through a
rollback journal and verifies the new Windows service.

**Tech stack:** PHP 8.5, Slim 4, ZipArchive, PHPUnit 13, PowerShell 2.0/CLR 2.0
syntax boundary, Windows SCM/WMI/icacls, Go 1.20.14 legacy build.

## Global Constraints

- No network command or TLS bootstrap runs on legacy Windows.
- No token is printed, embedded in a command line, or added to diagnostic text.
- No old runtime is modified before binary validation, `check`, and `migrate`
  have all succeeded.
- Every critical native executable result is checked explicitly.
- All ACL identities are locale-independent numeric SIDs.
- Modern Windows/Linux installation and agent API behavior remain unchanged.
- Each behavior is introduced by a failing focused test before implementation.

### Task 1: Package contract and server-side ZIP assembly

**Files:**
- Create: `src/Services/LegacyWindowsPackage.php`
- Create: `src/Services/LegacyWindowsPackageService.php`
- Create: `tests/Unit/Services/LegacyWindowsPackageServiceTest.php`
- Create: `resources/agent/windows-legacy/install.bat`
- Create: `resources/agent/windows-legacy/mirvmon-install-legacy.ps1`
- Modify: `composer.json`
- Modify: `composer.lock`

- [ ] Write failing tests for the four exact ZIP entries, exact catalog EXE
      bytes, catalog checksum/version rendering, ASCII/no-BOM server config,
      paths containing spaces, and deterministic entry names.
- [ ] Write failing static contracts forbidding network tools,
      `-EncodedCommand`, `$PSScriptRoot`, localized ACL names, and post-PS2
      constructs.
- [ ] Add `ext-zip` as a platform requirement and implement a bounded temporary
      ZIP builder using `ZipArchive` with cleanup on every path.
- [ ] Add the readable BAT and PS2 installer template with safe placeholder
      rendering that cannot alter PowerShell syntax.
- [ ] Run focused unit tests and commit the package-contract stage.

### Task 2: Transactional PowerShell installer

**Files:**
- Modify: `resources/agent/windows-legacy/mirvmon-install-legacy.ps1`
- Modify: `tests/Unit/Services/LegacyWindowsPackageServiceTest.php`
- Create: `tests/Unit/Contracts/LegacyWindowsInstallerContractTest.php`

- [ ] Write failing ordering tests proving `version`, initial `check`, `migrate`,
      and staged-config `check` precede the first service/task stop or file
      replacement.
- [ ] Write failing contracts for SHA-256, exact release/artifact validation,
      explicit `$LASTEXITCODE`, stage/program/exit diagnostics, and token-safe
      output.
- [ ] Implement prepare/validate helpers and legacy config/queue discovery.
- [ ] Write failing contracts for timestamped backups, service metadata capture,
      scheduled-task preservation, transaction journal, quoted `binPath`,
      bounded Running-state verification, and rollback.
- [ ] Implement commit/start/verify/cleanup with rollback for existing-service,
      scheduled-task-only, repeated-native, and clean-install states.
- [ ] Verify every `icacls` uses `*S-1-5-18` and
      `*S-1-5-32-544` and every critical `sc.exe`/`schtasks.exe`/`icacls.exe`
      path checks its exit code.
- [ ] Run focused contract and package tests and commit the transaction stage.

### Task 3: HTTP routes, credentials, and UI

**Files:**
- Modify: `src/Controllers/AgentController.php`
- Modify: `src/Controllers/ServerController.php`
- Modify: `src/Application/Bootstrap.php`
- Modify: `src/Application/AppFactory.php`
- Modify: `templates/servers/created.twig`
- Modify: `tests/Integration/Controllers/AgentControllerTest.php`
- Modify: `tests/Integration/Controllers/ServerControllerTest.php`

- [ ] Write failing integration tests for canonical
      `/agent/install-legacy.zip`, one-time credential consumption, ZIP response
      metadata/no-store protections, and both old-route aliases.
- [ ] Inject the package service lazily from the verified artifact catalog.
- [ ] Replace the two legacy UI links/tokens with one ZIP link/token while
      leaving modern installer routes unchanged.
- [ ] Update stateless-route declarations for the canonical endpoint.
- [ ] Run controller and rendering tests and commit the delivery stage.

### Task 4: Build, runtime dependencies, and release contracts

**Files:**
- Modify: `docker/Dockerfile`
- Modify: `docker/entrypoint.sh`
- Modify: `.github/workflows/ci.yml`
- Modify: `tests/Unit/Services/AgentArtifactCatalogTest.php` if required
- Create or modify: focused Docker/release contract tests in the existing test
  layout

- [ ] Write failing checks showing PHP ZIP support is absent from the current
      build/runtime declarations.
- [ ] Install/check `zip` through the existing PHP extension mechanism and add
      it to CI setup.
- [ ] Assert the legacy stage remains pinned to Go 1.20.14 with
      `CGO_ENABLED=0`, `GOARCH=amd64`, and `GOAMD64=v1`; do not change the modern
      toolchain.
- [ ] Assert release package EXE bytes and manifest checksum match and that Go
      1.20.14 can test/race-test/cross-build the agent.
- [ ] Run Composer platform validation, Docker build, and focused release
      checks; commit the build stage.

### Task 5: Documentation and operator acceptance

**Files:**
- Modify: `README.md`
- Modify: `INSTALL.md`
- Modify: `ARCHITECTURE.md`
- Modify: `TECHNICAL_SPECIFICATION.md`
- Modify: `AGENTS.md` only where its public endpoint list must match behavior

- [ ] Document downloading/extracting the ZIP, running `install.bat` as
      Administrator, expected stages, backup paths, rollback, and repeated
      installation.
- [ ] Document the Go 1.20.14 compatibility boundary and distinguish build
      verification from real Server 2008 R2 execution.
- [ ] Add a target-host acceptance checklist covering clean install, old Python
      migration, old PowerShell queue migration, failed-validation preservation,
      service state, metrics delivery, and self-update capability.
- [ ] Run documentation/code searches proving no public real deployment domain
      or secret was added; commit documentation.

### Task 6: Full verification and handoff

- [ ] Run `composer test`, `composer analyse`, `composer validate --strict`, and
      `composer audit` with the integration database enabled where available.
- [ ] Run Go tests/race tests under Go 1.20.14 and the current Go toolchain,
      format checks, and Windows legacy amd64 cross-build.
- [ ] Run npm asset reproduction/audit, shellcheck, Compose validation, and
      Docker build.
- [ ] Start the clean two-container stack and verify `/livez` and `/readyz`.
- [ ] Perform the required desktop/390 px browser smoke if UI markup changed.
- [ ] Review the diff for token exposure, destructive-before-validation order,
      localized ACLs, unchecked native exits, and unrelated changes.
- [ ] Report changed files, exact automated results, the exact ZIP download
      endpoint/filename, and the remaining real Server 2008 R2 runtime test.
