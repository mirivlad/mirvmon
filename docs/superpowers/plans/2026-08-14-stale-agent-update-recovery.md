# Stale Agent Update Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure an unacknowledged update command targeting an obsolete release is transactionally replaced and delivered when the agent next polls.

**Architecture:** `AgentUpdateRepository` owns the locked transaction that terminalizes the obsolete row and creates one replacement. `AgentUpdateService` invokes that operation only for a `pending` command whose target differs from the current allowlisted catalog; later states remain untouched.

**Tech Stack:** PHP 8.5, PDO/PostgreSQL 17, PHPUnit 13, Twig 3.

## Global Constraints

- Replace only `pending` commands; never supersede an agent-acknowledged command.
- Preserve `requested_by` and the allowlisted artifact key.
- Keep one active update command per server under concurrent polls.
- Use `target_superseded` as the old command's terminal error code.
- Do not change the agent protocol or database schema.

---

### Task 1: Repository replacement transaction

**Files:**
- Modify: `src/Repositories/AgentUpdateRepository.php`
- Test: `tests/Integration/Repositories/AgentUpdateRepositoryTest.php`

**Interfaces:**
- Consumes: an existing active command selected by `server_id`.
- Produces: `replacePendingTarget(int $serverId, string $targetVersion, string $targetArtifact): ?array`.

- [ ] **Step 1: Write failing integration tests**

Add tests that create an obsolete `pending` command, call
`replacePendingTarget()`, and assert a new UUID/current target plus an old
`failed` row with `target_superseded`. Repeat the call and assert the same active
UUID. Advance another obsolete command to `accepted` and assert it is returned
unchanged.

- [ ] **Step 2: Run focused tests and confirm the missing method fails**

Run:

```bash
vendor/bin/phpunit tests/Integration/Repositories/AgentUpdateRepositoryTest.php
```

Expected: failure because `replacePendingTarget()` is undefined.

- [ ] **Step 3: Implement the locked replacement**

Add the public method. Begin a transaction only if the caller does not already
own one, select the active command `FOR UPDATE`, return it unchanged unless it
is obsolete and `pending`, update the old row to terminal `failed`, insert a new
row using `uuidV4()`, and commit or roll back using the repository's existing
transaction conventions.

- [ ] **Step 4: Run the repository test**

Run the focused PHPUnit command again. Expected: all repository tests pass.

### Task 2: Poll-time delivery and UI copy

**Files:**
- Modify: `src/Services/AgentUpdateService.php`
- Modify: `templates/servers/partials/agent-management.twig`
- Test: `tests/Integration/Services/AgentUpdateServiceTest.php`

**Interfaces:**
- Consumes: `AgentUpdateRepository::replacePendingTarget()`.
- Produces: a current catalog command response from `commandForServer()`.

- [ ] **Step 1: Write failing service tests**

Create an obsolete `pending` command, poll through `commandForServer()`, and
assert the returned command uses the current target and a new UUID while the
old row is failed with `target_superseded`. Add a case proving an `accepted`
obsolete command is not replaced or delivered.

- [ ] **Step 2: Run focused service tests and confirm failure**

Run:

```bash
vendor/bin/phpunit tests/Integration/Services/AgentUpdateServiceTest.php
```

Expected: the obsolete `pending` command is not delivered before the fix.

- [ ] **Step 3: Implement minimal service orchestration**

Validate the active command's artifact against the catalog, call the repository
replacement for an obsolete `pending` target, then retain the existing exact
catalog-version guard and typed command response. Add a Russian UI label for
`target_superseded`.

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
- Consumes: poll-time replacement behavior from Task 2.
- Produces: documented recovery procedure and safety boundary.

- [ ] **Step 1: Document automatic supersession**

Explain that only obsolete `pending` commands are replaced and acknowledged
states are preserved.

- [ ] **Step 2: Document recoverable v0.4.5 cleanup**

Provide exact commands that stop both units, move coordination files into a
root-only timestamped backup, and restart the units. State that the token is
preserved.

- [ ] **Step 3: Run release checks**

Run `composer test`, `composer analyse`, `composer validate --strict`, and
`composer audit`. Expected: every command exits zero; integration tests must run
against a clean TimescaleDB rather than be counted as skipped.

- [ ] **Step 4: Integrate and release**

Commit each completed stage, merge the branch into `master`, push `master`, tag
the resulting commit as `v0.4.8`, push the tag, and use `gh run list` to confirm
the tag-triggered workflow is queued or running without waiting for completion.

