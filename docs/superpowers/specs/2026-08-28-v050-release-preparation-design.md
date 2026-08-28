# MirvMon v0.5.0 Release Preparation Design

## Goal

Prepare the website-monitoring feature for release `v0.5.0` without changing
its runtime contract or publishing anything before every release gate is green.

## Scope

- Capture six real, Russian-language UI views from the current branch with
  local fixture data only: dashboard, server list, server overview, server
  metrics, website list, and website settings.
- Replace the README gallery with those captures and make website monitoring
  visible in the release-facing description.
- Add a human-readable release note at `docs/releases/v0.5.0.md`.
- Set the documented default image reference to `0.5.0` where the existing
  examples currently name the previous release.

## Screenshot Contract

The captures are committed WebP assets under `docs/screenshots/`. They contain
no real hostname, credential, token, email address, IP address, or private
metric data. Every screenshot is taken from the current code and has a stable,
descriptive README alt text. The desktop viewport is 1280 px wide when the
browser backend clamps a wider requested viewport.

## Release Boundary

No Git tag, push, GitHub release, GHCR publication, merge, or deployment is
performed during preparation. The release is eligible only after PHP,
TimescaleDB integration, frontend, agent, shell/Compose, Docker image build,
and clean two-container `/livez` and `/readyz` checks succeed. The existing
user containers remain untouched; image smoke uses an isolated Compose project
and temporary resources.
