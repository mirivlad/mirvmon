# Public documentation and licence design

## Goal

Prepare the repository for public distribution by applying the MIT licence and
making the current web interface visible in the self-contained README.

## Scope

- Add an MIT `LICENSE` with copyright attributed to MirvMon contributors.
- Add a licence badge and a compact screenshots section to `README.md`.
- Store four lossless-source-derived, optimised WebP screenshots under
  `docs/screenshots/`: dashboard, server detail, groups, and notification
  settings.
- Capture the screens from the local authenticated development stack at a
  stable desktop viewport.  The fixture must contain only synthetic data and
  screenshots must not expose passwords, tokens, hostnames, or other secrets.
- Extend the existing documentation contract so the licence and all four
  referenced screenshot files are required by tests.

## Alternatives considered

1. Commit PNG files directly to the README. This preserves maximum image
   fidelity but makes the repository unnecessarily large.
2. Commit WebP screenshots in `docs/screenshots/` and reference them from the
   README. This keeps the documentation portable, reviewable and compact.
3. Host screenshots on an external CDN. This adds an availability dependency
   that is inappropriate for self-hosted product documentation.

Option 2 is selected.

## Presentation

The README will show the four screenshots as responsive linked previews below
the architecture overview. Each image includes meaningful alt text and opens
its full file when selected.  No image is used as the only way to communicate a
feature: the existing textual installation, agent and notification guidance
remains authoritative.

## Verification

- The documentation contract verifies the MIT licence text and every declared
  screenshot path.
- Image metadata confirms WebP format and non-zero dimensions.
- The README renders all four local image paths with no external image URL.
- The existing CI workflow runs the documentation contract on every push.

## Non-goals

- No external image hosting, tracking, generated documentation site, or new
  runtime dependency.
- No functional change to the application or its deployment topology.
