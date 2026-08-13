# Unified Windows Installer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all Windows script/ZIP downloads with one unsigned,
personalized Windows x64 EXE that selects the compatible native agent and
installs it transactionally.

**Architecture:** PHP validates a short-lived installer credential and builds
an NSIS executable from two catalog-verified Windows artifacts, a protected
bootstrap file, and the shared PS2 transaction. The selected Go agent exchanges
the credential through a new bounded activation endpoint, writes the permanent
configuration, and the existing migration/rollback sequence installs it.

**Tech Stack:** PHP 8.5, Slim 4, PHPUnit 13, NSIS 3, PowerShell 2.0/WMI/SCM,
Go 1.20.14 and Go 1.26.5, `golang.org/x/sys/windows`.

## Global Constraints

- Windows support is x64 only.
- Legacy artifact: Windows 7 SP1/8/8.1 and Server 2008 R2 SP1/2012/2012 R2,
  built with Go 1.20.14.
- Modern artifact: Windows 10/11 and Server 2016/2019/2022/2025, built with the
  current Go toolchain.
- The EXE is unsigned and the UI must say so plainly.
- The EXE contains only a one-hour installer credential, never a permanent
  agent token.
- Secrets never appear in compiler/process arguments, logs, or errors.
- Existing agent state is not modified before validation and migration pass.
- The server UI contains exactly two download buttons: Linux and Windows.
- Every behavior change starts with a focused failing test.

---

### Task 1: Installer credential activation contract

**Files:**
- Modify: `src/Services/AgentCredentialIssuer.php`
- Modify: `src/Controllers/AgentController.php`
- Modify: `src/Application/AppFactory.php`
- Test: `tests/Integration/Services/AgentCredentialIssuerTest.php`
- Test: `tests/Integration/Controllers/AgentControllerTest.php`
- Test: `tests/Contract/RouteSecurityContractTest.php`

**Interfaces:**
- Produces: `AgentCredentialIssuer::validateInstaller(string): bool` without
  consumption and `AgentController::activateInstaller()` returning native
  configuration JSON after `exchange()`.
- Consumes: existing deterministic `AgentCredential` and public URL resolver.

- [ ] **Step 1: Write failing credential tests** proving validation accepts one
  live token without consuming it, rejects malformed/expired/consumed tokens,
  and `exchange()` still consumes atomically.
- [ ] **Step 2: Run RED check:**
  `composer test -- --filter AgentCredentialIssuerTest`; expect missing
  `validateInstaller` failure.
- [ ] **Step 3: Implement the read-only validation query** using the same
  format, expiry, and `consumed_at IS NULL` constraints as `exchange()`.
- [ ] **Step 4: Write failing controller/route tests** for
  `POST /api/v1/agent/install`, Bearer installer authentication, complete
  config fields, `no-store`, generic 401, one-time consumption, and stateless
  routing.
- [ ] **Step 5: Implement activation** with a fixed 64 KiB response shape and
  no token-bearing diagnostics.
- [ ] **Step 6: Run GREEN checks:** focused credential, controller, and route
  contract tests must pass.
- [ ] **Step 7: Commit:**
  `git commit -m "feat(agent): add one-time Windows activation"`.

### Task 2: Native activation client

**Files:**
- Create: `agent/internal/enroll/client.go`
- Create: `agent/internal/enroll/client_test.go`
- Modify: `agent/cmd/mirvmon-agent/main.go`
- Modify: `agent/cmd/mirvmon-agent/main_test.go`

**Interfaces:**
- Produces: `enroll.Activate(context.Context, Request) error`, where `Request`
  contains `BootstrapPath`, `OutputConfig`, and an optional `HTTPClient`.
- Produces CLI: `activate --bootstrap <path> --output-config <path>`.
- Consumes: `config.Config.Validate` and `config.WriteAtomic`.

- [ ] **Step 1: Write failing enrollment tests** using `httptest.Server` for
  POST method/path, Bearer credential, valid config output, 64 KiB response
  limit, non-2xx rejection, TLS verification, malformed bootstrap/config, and
  secret-free errors.
- [ ] **Step 2: Run RED check:** `(cd agent && go test ./internal/enroll)`;
  expect the package/API to be missing.
- [ ] **Step 3: Implement the smallest bounded enrollment client** that
  validates `base_url`, accepts only a 64-hex credential, sends no request
  body, decodes one JSON object, validates it, and atomically writes the output.
- [ ] **Step 4: Write failing CLI tests** for valid flags, incomplete flags,
  generic errors, and absence of secret values in stdout/stderr.
- [ ] **Step 5: Wire the `activate` command** without changing run/check/migrate
  semantics.
- [ ] **Step 6: Run GREEN checks:** `(cd agent && go test ./...)` and
  `(cd agent && go test -race ./...)`.
- [ ] **Step 7: Commit:**
  `git commit -m "feat(agent): activate Windows installations"`.

### Task 3: Unified transactional Windows payload

**Files:**
- Create: `resources/agent/windows/mirvmon-install.ps1`
- Create: `resources/agent/windows/installer.nsi`
- Create: `tests/Unit/Contracts/WindowsInstallerContractTest.php`
- Remove: `resources/agent/windows-legacy/install.bat`
- Remove: `resources/agent/windows-legacy/mirvmon-install-legacy.ps1`

**Interfaces:**
- Produces: one PS2-compatible transaction accepting adjacent
  `mirvmon-agent-modern.exe`, `mirvmon-agent-legacy.exe`, and `bootstrap.json`.
- Produces: NSIS compiler inputs `PAYLOAD_DIR` and `OUTPUT_FILE`.
- Consumes: both agent `version`, `activate`, `check`, and `migrate` commands.

- [ ] **Step 1: Write failing static contracts** for x64 enforcement, WMI
  version mapping (6.1 SP1/6.2/6.3 legacy; 10+ modern), unsupported rejection,
  both payload names, and selection before activation.
- [ ] **Step 2: Write failing transaction contracts** retaining SHA-256/size/
  identity checks, SID ACLs, queue fallback, quiesced second migration,
  service verification, and rollback ordering.
- [ ] **Step 3: Generalize the proven legacy PS2 transaction** with two
  rendered artifact identities and native activation, while keeping all
  destructive operations after preflight.
- [ ] **Step 4: Write failing NSIS contracts** for `RequestExecutionLevel
  admin`, supported-OS manifest, solid compression, hidden temporary payload,
  exact PowerShell exit propagation, and no credential compiler define.
- [ ] **Step 5: Implement the minimal NSIS wrapper** which extracts payload to
  `$PLUGINSDIR`, runs the PS2 transaction, shows a useful failure message, and
  never offers a false success.
- [ ] **Step 6: Run GREEN check:**
  `composer test -- --filter WindowsInstallerContractTest`.
- [ ] **Step 7: Commit:**
  `git commit -m "feat(installer): unify Windows installation transaction"`.

### Task 4: Dynamic NSIS package service

**Files:**
- Create: `src/Services/WindowsInstallerPackage.php`
- Create: `src/Services/WindowsInstallerPackageService.php`
- Create: `tests/Unit/Services/WindowsInstallerPackageServiceTest.php`
- Remove: `src/Services/LegacyWindowsPackage.php`
- Remove: `src/Services/LegacyWindowsPackageService.php`
- Remove: `tests/Unit/Services/LegacyWindowsPackageServiceTest.php`
- Remove: `tests/Unit/Contracts/LegacyWindowsInstallerContractTest.php`

**Interfaces:**
- Produces: `WindowsInstallerPackageService::build(string $baseUrl, string
  $installerCredential, AgentArtifactCatalog $catalog):
  WindowsInstallerPackage`.
- Consumes: `/usr/bin/makensis`, both catalog artifacts, fixed templates, and a
  private temporary directory.

- [ ] **Step 1: Write failing service tests** with a fake compiler for exact
  payload bytes, bootstrap fields, rendered metadata, argv secrecy, EXE name/
  MIME, `MZ` validation, compiler non-zero output, missing artifact/template,
  and cleanup after success/failure.
- [ ] **Step 2: Run RED check:**
  `composer test -- --filter WindowsInstallerPackageServiceTest`; expect class
  missing.
- [ ] **Step 3: Implement secure temporary assembly and `proc_open` array
  invocation** with no shell, bounded diagnostics, output verification, and
  recursive cleanup restricted to the created directory.
- [ ] **Step 4: Run GREEN focused package tests.**
- [ ] **Step 5: Commit:**
  `git commit -m "feat(server): build personalized Windows EXE"`.

### Task 5: Routes and two-button server UI

**Files:**
- Modify: `src/Controllers/AgentController.php`
- Modify: `src/Controllers/ServerController.php`
- Modify: `src/Application/Bootstrap.php`
- Modify: `src/Application/AppFactory.php`
- Modify: `templates/servers/created.twig`
- Modify: `templates/servers/partials/agent-management.twig`
- Modify: `tests/Integration/Controllers/AgentControllerTest.php`
- Modify: `tests/Integration/Controllers/ServerControllerTest.php`
- Modify: `tests/Unit/Templates/AgentManagementTemplateTest.php`

**Interfaces:**
- Produces canonical `GET /agent/install.exe?token=...`.
- Produces `installer_tokens` shape `{linux: string, windows: string}`.
- Consumes `WindowsInstallerPackageService` and its verified EXE response.

- [ ] **Step 1: Write failing route/controller tests** for valid unconsumed
  credential download, invalid/consumed 403, EXE headers, compiler failure,
  and removal of all five script/ZIP endpoints.
- [ ] **Step 2: Write failing rendering tests** asserting exactly the Linux and
  Windows links, Windows matrix/help/unsigned warning, and absence of PS1/BAT/
  ZIP labels and URLs on creation and Agent-tab flows.
- [ ] **Step 3: Inject the new package service and replace controller routes**;
  validate without consuming at download and consume only through activation.
- [ ] **Step 4: Reduce token issuance to two credentials and update both UI
  locations** without restoring installer controls to server editing.
- [ ] **Step 5: Run focused controller/template tests.**
- [ ] **Step 6: Commit:**
  `git commit -m "feat(ui): offer one Windows agent installer"`.

### Task 6: Image, CI, and dependency cleanup

**Files:**
- Modify: `docker/Dockerfile`
- Modify: `docker/entrypoint.sh`
- Modify: `.github/workflows/ci.yml`
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `tests/Contract/ComposeContractTest.php`

**Interfaces:**
- Produces production `/usr/bin/makensis` and startup failure when it is absent.
- Removes `ext-zip` only if `rg 'ZipArchive' src` proves no remaining runtime
  consumer.

- [ ] **Step 1: Write failing build contracts** for the NSIS package/runtime
  check and absence of obsolete ZIP-only declarations.
- [ ] **Step 2: Add `nsis` to the image**, check `makensis` at startup, and add
  a CI compile smoke using fixture payloads and the real `.nsi` template.
- [ ] **Step 3: Remove obsolete `ext-zip` requirements** only after local code
  search proves the package service was its sole consumer; regenerate lockfile.
- [ ] **Step 4: Run focused contracts, Composer validation, real NSIS compile,
  and both Go Windows amd64 cross-builds.**
- [ ] **Step 5: Commit:**
  `git commit -m "build: add unified Windows installer toolchain"`.

### Task 7: Documentation and verification

**Files:**
- Modify: `README.md`
- Modify: `INSTALL.md`
- Modify: `ARCHITECTURE.md`
- Modify: `TECHNICAL_SPECIFICATION.md`
- Modify: `AGENTS.md`

**Interfaces:**
- Produces the exact supported Windows matrix, EXE workflow, activation
  security boundary, unsigned warning, migration/rollback behavior, and manual
  smoke checklist.

- [ ] **Step 1: Replace script/ZIP and incorrect Server 2012 Go-boundary docs**
  with the canonical EXE, both toolchains, and all supported x64 systems.
- [ ] **Step 2: Document operator acceptance** for Windows 7 SP1/Server 2008 R2
  SP1, Windows 8.1/Server 2012 R2, and Windows 11/Server 2022, including UAC,
  unsigned warning, migration, service, metrics, and self-update.
- [ ] **Step 3: Run full PHP checks:** `composer test`, `composer analyse`,
  `composer validate --strict`, `composer audit`.
- [ ] **Step 4: Run full agent checks:** `(cd agent && gofmt -d .)`, `(cd agent
  && go test ./...)`, `(cd agent && go test -race ./...)`, Go 1.20.14 tests,
  and both Windows cross-builds.
- [ ] **Step 5: Run deployment/frontend checks:** `npm ci`, assets diff, npm
  audit, shellcheck, Compose config, Docker build, clean two-container livez/
  readyz, and desktop/390 px browser smoke.
- [ ] **Step 6: Review the final diff** for secret leakage, compiler argv,
  preflight ordering, rollback, unsupported OS handling, removed routes, and
  unrelated changes.
- [ ] **Step 7: Commit:**
  `git commit -m "docs: describe unified Windows installation"`.
