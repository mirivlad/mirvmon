# Agent Self-Update and Server Icons Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add administrator-triggered, rollback-capable native-agent updates and fix OS/status icons across all server views.

**Architecture:** The server persists one typed update command per agent and returns it through the existing outbound config poll. A capable agent downloads only its fixed same-origin artifact, verifies version and SHA-256, records progress durably, and delegates replacement to a constrained platform updater. A shared PHP platform classifier and Twig partial render the same OS family and status colour in every view.

**Tech Stack:** Go 1.20.14/1.26.5, PHP 8.5, Slim 4, Twig 3, PostgreSQL 17/TimescaleDB 2.28, systemd 219+, Windows SCM, PHPUnit 13, Docker BuildKit.

## Global Constraints

- Self-update supports only `linux-amd64`, `windows-amd64`, and `windows-legacy-amd64`.
- `v0.4.3` is the first capable release; older agents require one manual installer run.
- The agent remains outbound-only and opens no listener.
- No command contains arbitrary executable text, URL, filesystem path, or secret.
- Linux collection remains unprivileged; only the fixed apply-update one-shot runs as root.
- Config, permanent token, queue, and service identity survive updates unchanged.
- Target version must be valid semver and strictly newer than the installed version.
- Every behavior change follows RED/GREEN TDD and each task ends in a focused commit.

---

### Task 1: Versioned artifacts and agent identity

**Files:**
- Modify: `docker/Dockerfile`
- Modify: `agent/internal/buildinfo/info.go`
- Modify: `agent/internal/protocol/envelope.go`
- Modify: `agent/internal/protocol/envelope_test.go`
- Modify: `agent/internal/runner/runner.go`
- Modify: `agent/internal/runner/runner_test.go`
- Modify: `src/Services/AgentArtifact.php`
- Modify: `src/Services/AgentArtifactCatalog.php`
- Modify: `tests/Unit/Services/AgentArtifactCatalogTest.php`

**Interfaces:**
- Produces: build-time `buildinfo.Artifact`, envelope fields `agent_artifact` and `agent_capabilities`, and `AgentArtifactCatalog::version(): string`.

- [ ] Write failing Go tests asserting envelopes contain the linker artifact key and `self_update_v1`.
- [ ] Run `cd agent && go test ./internal/protocol ./internal/runner`; expect missing identity fields.
- [ ] Add `Artifact = "development"`, pass it through runner dependencies, and serialize the two bounded optional envelope fields.
- [ ] Write failing PHP tests for a manifest shaped as:

```json
{"version":"v0.4.3","artifacts":{"linux-amd64":{"filename":"mirvmon-agent-linux-amd64","sha256":"…","content_type":"application/octet-stream"}}}
```

- [ ] Run `./vendor/bin/phpunit tests/Unit/Services/AgentArtifactCatalogTest.php`; expect rejection of `version`.
- [ ] Add strict release-version validation and expose it through the catalog and artifact DTO.
- [ ] Inject exact artifact keys through all Docker build stages and include the release version in `manifest.json`.
- [ ] Run focused Go/PHP tests and commit `feat(agent): publish self-update identity`.

### Task 2: Persist capabilities and update commands

**Files:**
- Create: `migrations/015_agent_self_updates.sql`
- Create: `src/Repositories/AgentUpdateRepository.php`
- Create: `tests/Integration/Repositories/AgentUpdateRepositoryTest.php`
- Modify: `src/Domain/Metrics/MetricsEnvelope.php`
- Modify: `src/Domain/Metrics/MetricsValidator.php`
- Modify: `src/Services/MetricsIngestionService.php`
- Modify: `tests/Unit/Domain/Metrics/MetricsValidatorTest.php`
- Modify: `tests/Integration/Services/MetricsIngestionServiceTest.php`
- Modify: `tests/Integration/Database/SchemaTest.php`

**Interfaces:**
- Produces: `AgentUpdateRepository::create`, `activeForServer`, `advance`, `fail`, `completeForReportedVersion`, and `latestForServer`.

- [ ] Write failing schema and validator tests for the new agent identity fields and command-state constraints.
- [ ] Run the focused database/validator tests; expect missing columns and validation fields.
- [ ] Add nullable `agent_artifact VARCHAR(64)`, non-null JSONB capabilities, and `agent_update_commands` with UUID, target data, bounded state/error, requester, timestamps, and a partial unique active-command index.
- [ ] Persist identity during metrics ingestion without clearing values from older envelopes.
- [ ] Write failing repository tests for one-active-command enforcement, monotonic transitions, terminal retry, and completion only when metrics report the exact target version.
- [ ] Implement the repository with transactions and row locks; terminal states are `succeeded` and `failed`.
- [ ] Run focused tests and commit `feat(server): persist agent update commands`.

### Task 3: Strict version eligibility and authenticated command API

**Files:**
- Create: `src/Services/AgentVersionService.php`
- Create: `src/Services/AgentUpdateService.php`
- Create: `src/Controllers/AgentUpdateController.php`
- Create: `tests/Unit/Services/AgentVersionServiceTest.php`
- Create: `tests/Integration/Controllers/AgentUpdateControllerTest.php`
- Modify: `src/Controllers/AgentController.php`
- Modify: `src/Application/Bootstrap.php`
- Modify: `src/Application/AppFactory.php`
- Modify: `tests/Integration/Controllers/AgentControllerTest.php`

**Interfaces:**
- Produces: `AgentVersionService::isUpgrade(string $installed, string $available): bool`; admin `POST /servers/{id}/agent/update`; agent `POST /api/v1/agent/update/{command}/status`; optional config response `update_command`.

- [ ] Write failing semver tests covering `v0.4.2 < v0.4.3`, equality, downgrade, prerelease ordering, and invalid/development versions.
- [ ] Implement strict semver comparison without adding a dependency.
- [ ] Write failing integration tests proving only an admin with CSRF can create an eligible command and that old/incompatible agents receive no button or command.
- [ ] Implement `AgentUpdateService` eligibility using catalog version, stored capability, and allowlisted artifact.
- [ ] Extend the config response with only `id`, `target_version`, `artifact`, `sha256`, and `size` for the authenticated server.
- [ ] Implement bearer-owned progress reporting with an allowlist of forward transitions and bounded public error codes.
- [ ] Verify auth, ownership, replay, downgrade, and cross-server rejection; commit `feat(server): deliver authenticated update commands`.

### Task 4: Agent command state and secure download

**Files:**
- Create: `agent/internal/update/state.go`
- Create: `agent/internal/update/state_test.go`
- Create: `agent/internal/update/download.go`
- Create: `agent/internal/update/download_test.go`
- Modify: `agent/internal/config/config.go`
- Modify: `agent/internal/config/config_test.go`
- Modify: `agent/internal/transport/client.go`
- Modify: `agent/internal/transport/client_test.go`
- Modify: `agent/internal/runner/runner.go`
- Modify: `agent/internal/runner/runner_test.go`

**Interfaces:**
- Produces: `update.Command`, `update.Store`, `update.Downloader.Stage`, transport `ReportUpdate`, and runner update coordination.

- [ ] Write failing tests for typed command decoding, unknown-field compatibility, command UUID replay, and rejection of a second active UUID.
- [ ] Implement atomic local state beside the queue with no token or response body stored.
- [ ] Write failing download tests for same-origin fixed endpoints, TLS, redirect refusal, maximum size, exact checksum, partial-file cleanup, and executable permissions.
- [ ] Implement a streaming bounded downloader using the transport client and fixed artifact key from `buildinfo`.
- [ ] Add progress reporting and ensure collection/delivery continues until the platform handoff begins.
- [ ] Run `go test` and `go test -race` for affected packages; commit `feat(agent): stage authenticated updates safely`.

### Task 5: Platform replacement and rollback

**Files:**
- Create: `agent/internal/update/apply.go`
- Create: `agent/internal/update/apply_test.go`
- Create: `agent/internal/update/apply_unix.go`
- Create: `agent/internal/update/apply_windows.go`
- Modify: `agent/cmd/mirvmon-agent/main.go`
- Modify: `agent/cmd/mirvmon-agent/main_test.go`
- Modify: `agent/internal/health/store.go`
- Modify: `agent/internal/health/store_test.go`

**Interfaces:**
- Produces: CLI `apply-update --config <path> --request <path>` and injected `ServiceController`/filesystem/process boundaries.

- [ ] Write failing platform-neutral tests for request validation, backup, atomic replacement, health confirmation, rollback, and sanitized failure results.
- [ ] Implement shared apply workflow with exact installed/staged/backup paths derived locally, never from server JSON.
- [ ] Write failing Linux tests for root requirement, owner/mode validation, atomic rename, restart, and rollback.
- [ ] Implement the Unix boundary with build tags and systemd commands compatible with version 219.
- [ ] Write failing Windows tests for protected helper copy, parent exit wait, SCM stop/start, locked-EXE replacement, and rollback.
- [ ] Implement the Windows boundary using Go 1.20-compatible APIs and no PowerShell dependency.
- [ ] Cross-compile all three artifacts and commit `feat(agent): apply updates with rollback`.

### Task 6: Install updater privileges safely

**Files:**
- Modify: `src/Services/AgentInstallerService.php`
- Modify: `tests/Unit/Services/AgentInstallerServiceTest.php`
- Modify: `tests/Integration/Controllers/AgentControllerTest.php`

**Interfaces:**
- Linux installer produces `mirvmon-agent-update.path` and root one-shot `mirvmon-agent-update.service`; Windows retains protected LocalSystem service paths.

- [ ] Write failing installer tests for fixed unit paths, root ownership, state-directory permissions, unit enablement, and removal during failed staging.
- [ ] Add Linux path/one-shot units only after the staged binary passes `check`; enable them together with the agent service.
- [ ] Add Windows ACL assertions for staged update/helper/result files and preserve legacy-compatible script syntax.
- [ ] Verify installer generation for Linux, modern Windows, and legacy Windows; commit `feat(installer): provision privileged update helpers`.

### Task 7: Update-management UI

**Files:**
- Modify: `src/Controllers/ServerDetailController.php`
- Modify: `src/Controllers/ServerController.php`
- Modify: `templates/servers/partials/agent-management.twig`
- Modify: `templates/servers/index.twig`
- Modify: `public/css/app.css`
- Modify: `tests/Integration/Controllers/ServerControllerTest.php`
- Modify: `tests/Integration/Controllers/AgentUpdateControllerTest.php`

**Interfaces:**
- Produces: Agent-tab version/status card and server-list update indicator.

- [ ] Write failing rendering tests for current, manual-required, update-available, pending, installing, succeeded, and failed states.
- [ ] Add a CSRF-protected admin update button, bounded Russian state text, and retry for terminal failures.
- [ ] Add an accessible icon/tooltip beside agent version linking to `/servers/{id}?tab=agent`.
- [ ] Verify non-admin users never receive an actionable update control; commit `feat(ui): manage agent updates`.

### Task 8: Fix issue #4 with one icon model

**Files:**
- Create: `src/Services/ServerPlatformService.php`
- Create: `tests/Unit/Services/ServerPlatformServiceTest.php`
- Create: `templates/partials/server-status-icon.twig`
- Modify: `src/Services/ServerStatusService.php`
- Modify: `templates/servers/index.twig`
- Modify: `templates/dashboard.twig`
- Modify: `templates/servers/detail.twig`
- Modify: `public/js/dashboard.js`
- Modify: `public/css/app.css`
- Modify: `tests/Unit/Services/ServerStatusServiceTest.php`
- Modify: `tests/Integration/Controllers/DashboardReadModelTest.php`

**Interfaces:**
- Produces: platform values `windows`, `linux`, `unknown`, Font Awesome class, tooltip, and status CSS class.

- [ ] Write failing classifier tests for Windows, Debian GNU/Linux, Ubuntu, CentOS, NethServer, Oracle Linux, RHEL, Rocky, AlmaLinux, empty, and unrecognised values; artifact key takes precedence.
- [ ] Implement the classifier and expose normalized icon metadata in server read models.
- [ ] Replace per-template icon logic with the shared partial on list, dashboard, and detail pages.
- [ ] Change dashboard live refresh to update only status colour/classes, preserving platform icon class.
- [ ] Verify `online`, `warning`, `critical`, and `offline` colours for known and unknown OS; commit `fix(ui): unify server OS and status icons`.

### Task 9: Documentation, full verification, and release

**Files:**
- Modify: `README.md`
- Modify: `INSTALL.md`
- Modify: `ARCHITECTURE.md`
- Modify: `TECHNICAL_SPECIFICATION.md`
- Modify: `.github/workflows/ci.yml` only if a missing update-specific check is demonstrated.

- [ ] Document manual bootstrap of `v0.4.3`, command flow, privileges, security validation, rollback, UI states, and troubleshooting.
- [ ] Run Go unit/race tests and modern/legacy cross-builds.
- [ ] Run Composer tests with a clean TimescaleDB, analysis, validate, and audit.
- [ ] Run npm asset reproduction/audit, shellcheck, Compose validation, and Docker build.
- [ ] Run a clean two-container start and verify `/livez` and `/readyz`.
- [ ] Perform browser smoke on desktop and 390 px without console errors.
- [ ] Review the diff for secrets, arbitrary update inputs, migration edits, and unrelated changes.
- [ ] Commit `docs: document native agent self-update`, merge approved dependency changes, push `master`, tag `v0.4.3`, and report GitHub Actions/image status according to the user's release instruction.
