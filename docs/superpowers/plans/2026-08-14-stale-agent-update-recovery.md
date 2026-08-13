# Stale Agent Update Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure an update command targeting an obsolete release no longer blocks retry, without issuing an unsafe replacement UUID to a legacy agent.

**Architecture:** `AgentUpdateRepository` terminalizes the exact obsolete row under lock. `AgentUpdateService` invokes that operation only for a `pending` command whose target differs from the current allowlisted catalog; later states remain untouched and a new command requires explicit administrator retry after local cleanup.

**Tech Stack:** PHP 8.5, PDO/PostgreSQL 17, PHPUnit 13, Twig 3.

## Global Constraints

- Supersede only `pending` commands; never change an agent-acknowledged command.
- Do not automatically create a replacement UUID.
- Make repeated and concurrent supersession idempotent.
- Use `target_superseded` as the old command's terminal error code.
- Do not change the agent protocol or database schema.

---

### Task 1: Repository terminalization transaction

**Files:**
- Modify: `src/Repositories/AgentUpdateRepository.php`
- Test: `tests/Integration/Repositories/AgentUpdateRepositoryTest.php`

**Interfaces:**
- Consumes: the exact active command UUID and `server_id`.
- Produces: `supersedePending(string $id, int $serverId): bool`.

- [ ] **Step 1: Write failing integration tests**

Add tests that create an obsolete `pending` command, call
`supersedePending()`, and assert a `failed` row with `target_superseded` and no
active replacement. Repeat the call and assert it is idempotent. Advance another
obsolete command to `accepted` and assert it remains active and unchanged.

- [ ] **Step 2: Run focused tests and confirm the missing method fails**

Run:

```bash
vendor/bin/phpunit tests/Integration/Repositories/AgentUpdateRepositoryTest.php
```

Expected: failure because `supersedePending()` is undefined.

- [ ] **Step 3: Implement locked terminalization**

Add the public method using `withLockedCommand()`. Return true for an existing
`failed/target_superseded`, false for every state except `pending`, and update a
`pending` row to terminal `failed/target_superseded` with completion timestamps.

- [ ] **Step 4: Run the repository test**

Run the focused PHPUnit command again. Expected: all repository tests pass.

### Task 2: Poll-time delivery and UI copy

**Files:**
- Modify: `src/Services/AgentUpdateService.php`
- Modify: `templates/servers/partials/agent-management.twig`
- Test: `tests/Integration/Services/AgentUpdateServiceTest.php`

**Interfaces:**
- Consumes: `AgentUpdateRepository::supersedePending()`.
- Produces: safe refusal plus a retryable terminal UI state.

- [ ] **Step 1: Write failing service tests**

Create an obsolete `pending` command, poll through `commandForServer()`, and
assert no command is returned while the old row becomes
`failed/target_superseded`. Add a case proving an `accepted` obsolete command is
not changed or delivered.

- [ ] **Step 2: Run focused service tests and confirm failure**

Run:

```bash
vendor/bin/phpunit tests/Integration/Services/AgentUpdateServiceTest.php
```

Expected: the obsolete command remains active before the fix.

- [ ] **Step 3: Implement minimal service orchestration**

Validate the active command's artifact against the catalog, call repository
terminalization for an obsolete target, and return no command. Retain the
existing typed response for current targets. Add a Russian UI label instructing
the administrator to clean stale local state and retry.

- [ ] **Step 4: Run both focused integration tests**

Run:

```bash
vendor/bin/phpunit tests/Integration/Repositories/AgentUpdateRepositoryTest.php tests/Integration/Services/AgentUpdateServiceTest.php
```

Expected: all focused tests pass.

### Task 3: Operator documentation and release verification

**Files:**
- Modify: `README.md`
- Modify: `ARCHITECTURE.md`
- Modify: `INSTALL.md`

**Interfaces:**
- Consumes: poll-time terminalization behavior from Task 2.
- Produces: documented recovery procedure and safety boundary.

- [ ] **Step 1: Document automatic supersession**

Explain that only obsolete `pending` commands are terminalized, no replacement
UUID is created automatically, and acknowledged states are preserved.

- [ ] **Step 2: Document recoverable v0.4.5 cleanup**

Provide exact commands that stop the agent, path, and one-shot updater, move
coordination files into a root-only timestamped backup, and restart the path and
agent. State that the token is preserved and UI retry is required.

- [ ] **Step 3: Run release checks**

Run `composer test`, `composer analyse`, `composer validate --strict`, and
`composer audit`. Expected: every command exits zero; integration tests must run
against a clean TimescaleDB rather than be counted as skipped.

- [ ] **Step 4: Integrate and release**

Commit each completed stage, merge the branch into `master`, push `master`, tag
the resulting commit as `v0.4.8`, push the tag, and use `gh run list` to confirm
the tag-triggered workflow is queued or running without waiting for completion.
