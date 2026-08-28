# MirvMon v0.5.0 Release Preparation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce reviewable v0.5.0 release documentation and real website-monitoring screenshots, then prove the release candidate without publishing it.

**Architecture:** UI captures are static documentation assets from the current local application and anonymous fixture data. Release notes and README describe shipped behavior; feature code and release workflow stay unchanged. The production image and runtime are verified in an isolated Compose project before any release action.

**Tech Stack:** PHP 8.5, Slim/Twig, browser capture, WebP, Docker Compose, PostgreSQL 17 with TimescaleDB 2.28.

## Global Constraints

- Release target is exactly `v0.5.0`; Docker image tags omit the leading `v`.
- Do not expose domains, IP addresses, credentials, tokens, emails, or production metrics in committed images.
- Production Compose remains exactly `app` and `db`.
- Do not tag, push, publish, merge, or deploy until every listed release gate succeeds.
- Preserve pre-existing user containers; smoke uses a distinct Compose project and ports.

---

### Task 1: Capture the release UI gallery

**Files:**

- Create: `docs/screenshots/servers.webp`
- Create: `docs/screenshots/server-overview.webp`
- Create: `docs/screenshots/server-metrics.webp`
- Create: `docs/screenshots/websites.webp`
- Create: `docs/screenshots/website-settings.webp`
- Modify: `docs/screenshots/dashboard.webp`

**Interfaces:**

- Consumes: authenticated local UI and seeded fixture data.
- Produces: six WebP assets named by their README subject.

- [ ] Start the PHP app with explicit test `DB_*` variables and an asset router.
- [ ] Capture dashboard, `/servers`, server overview, server metrics, `/sites`, and site settings at desktop width.
- [ ] Inspect all six assets for correct dimensions and absence of secrets, real IPs, or real hostnames.
- [ ] Commit with `git add docs/screenshots && git commit -m "docs: refresh monitoring screenshots"`.

### Task 2: Make release documentation accurate

**Files:**

- Modify: `README.md`
- Create: `docs/releases/v0.5.0.md`
- Modify: `.env.example`
- Modify: `docker/.env.example`

**Interfaces:**

- Consumes: screenshot filenames from Task 1 and implemented website-monitoring behavior.
- Produces: discoverable release notes and `0.5.0` deployment examples.

- [ ] Replace the four-image README gallery with six images in three two-column rows and Russian alt texts.
- [ ] Create release notes covering website monitoring, operational behavior, dashboard/UI integration, and normal `bin/migrate` upgrade behavior.
- [ ] Change only the two `MIRVMON_IMAGE=ghcr.io/mirivlad/mirvmon:0.3.7` examples to `...:0.5.0`.
- [ ] Run `composer test -- --testsuite contract` and `git diff --check`, then commit with `git add README.md docs/releases/v0.5.0.md .env.example docker/.env.example && git commit -m "docs: prepare v0.5.0 release notes"`.

### Task 3: Prove the release candidate without publishing it

**Files:**

- Verify only; no intended source changes.

**Interfaces:**

- Consumes: Tasks 1-2 and a build host that can reach package mirrors.
- Produces: recorded evidence for the release decision.

- [ ] Run PHP, TimescaleDB, frontend, agent, shell, Compose and Docker-image gates.
- [ ] Build with `APP_VERSION=v0.5.0` after the host can reach Debian package mirrors.
- [ ] Start `docker compose -p mirvmon-v050-smoke` with temporary secrets and a distinct localhost port; require HTTP 200 from `/livez` and `/readyz` and `version: v0.5.0`.
- [ ] Remove only the isolated smoke project, confirm a clean worktree and that `origin` has no `v0.5.0`, then stop before tag/push/publication.
