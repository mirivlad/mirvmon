# Public Documentation Assets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish the project under MIT and show the current authenticated dashboard in a self-contained README gallery.

**Architecture:** `LICENSE` is the canonical legal document. Four optimized local WebP images in `docs/screenshots/` are embedded in README; the PHPUnit documentation contract proves that the license declaration, image files, format and references stay present in CI.

**Tech Stack:** PHP 8.5, PHPUnit 13, Markdown, Chromium browser automation, WebP.

## Global Constraints

- Use exactly `Copyright (c) 2026 MirvMon contributors` in the MIT text.
- Use exactly `MIT` for the Composer license field.
- Keep images local WebP files, with no external image host.
- Capture dashboard, server detail, groups and notification settings at a 1440 px desktop viewport.
- Use synthetic fixture data; screenshots must not reveal credentials, tokens, real hostnames or IP addresses.
- Do not change runtime behavior or the two-container topology.

---

### Task 1: Add and enforce the MIT license

**Files:**
- Create: `LICENSE`
- Modify: `composer.json:4`
- Modify: `README.md:1-8`
- Modify: `tests/Contract/DocumentationContractTest.php`

**Interfaces:**
- Consumes: the contract test suite loaded by `phpunit.xml`.
- Produces: canonical repository-root MIT license text and package metadata value `MIT`.

- [ ] **Step 1: Write the failing contract**

Add this method to `DocumentationContractTest`:

```php
public function testRepositoryDeclaresTheMITLicense(): void
{
    $root = dirname(__DIR__, 2);
    self::assertFileExists($root . '/LICENSE');
    $license = (string) file_get_contents($root . '/LICENSE');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    self::assertStringContainsString('MIT License', $license);
    self::assertStringContainsString('Copyright (c) 2026 MirvMon contributors', $license);
    self::assertSame('MIT', $composer['license']);
}
```

- [ ] **Step 2: Run the focused test and observe the red state**

Run `composer test -- tests/Contract/DocumentationContractTest.php --filter testRepositoryDeclaresTheMITLicense`.

Expected: failure because `LICENSE` does not exist and Composer declares `proprietary`.

- [ ] **Step 3: Add the minimal implementation**

Create `LICENSE` with canonical MIT terms beginning with:

```text
MIT License

Copyright (c) 2026 MirvMon contributors
```

Set this Composer value:

```json
"license": "MIT"
```

Add this immediately below the README title:

```markdown
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
```

- [ ] **Step 4: Run focused verification**

Run `composer test -- tests/Contract/DocumentationContractTest.php --filter testRepositoryDeclaresTheMITLicense && composer validate --strict`.

Expected: the contract passes and Composer reports a valid manifest.

- [ ] **Step 5: Commit the license change**

Run `git add LICENSE composer.json README.md tests/Contract/DocumentationContractTest.php && git commit -m "docs: publish project under MIT license"`.

### Task 2: Add a verified screenshot gallery

**Files:**
- Create: `docs/screenshots/dashboard.webp`
- Create: `docs/screenshots/server-detail.webp`
- Create: `docs/screenshots/groups.webp`
- Create: `docs/screenshots/notification-settings.webp`
- Modify: `README.md` after the deployment architecture section
- Modify: `tests/Contract/DocumentationContractTest.php`

**Interfaces:**
- Consumes: local app `http://127.0.0.1:18081`, authenticated synthetic review account, PHP `getimagesize()`.
- Produces: four non-empty WebP files and matching README relative links.

- [ ] **Step 1: Write the failing asset contract**

Add this method to `DocumentationContractTest`:

```php
public function testReadmeReferencesCompleteLocalScreenshotGallery(): void
{
    $root = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($root . '/README.md');

    foreach (['dashboard', 'server-detail', 'groups', 'notification-settings'] as $name) {
        $relativePath = 'docs/screenshots/' . $name . '.webp';
        $imagePath = $root . '/' . $relativePath;

        self::assertFileExists($imagePath);
        $image = getimagesize($imagePath);
        self::assertNotFalse($image);
        self::assertSame(IMAGETYPE_WEBP, $image[2]);
        self::assertGreaterThan(0, $image[0]);
        self::assertGreaterThan(0, $image[1]);
        self::assertStringContainsString('](' . $relativePath . ')', $readme);
    }
}
```

- [ ] **Step 2: Run the focused test and observe the red state**

Run `composer test -- tests/Contract/DocumentationContractTest.php --filter testReadmeReferencesCompleteLocalScreenshotGallery`.

Expected: failure because the images and README gallery do not exist.

- [ ] **Step 3: Capture clean local screenshots**

Use an authenticated browser at 1440 px viewport. Visually inspect `/`, a synthetic populated `/servers/{id}`, `/groups`, and `/admin/notifications`; ensure passwords and other secret inputs are empty before writing the four exact target paths.

- [ ] **Step 4: Embed responsive local previews**

Add this section after the deployment architecture prose:

```markdown
## Интерфейс

<p align="center">
  <a href="docs/screenshots/dashboard.webp"><img src="docs/screenshots/dashboard.webp" alt="Дашборд MirvMon с состоянием серверов" width="49%"></a>
  <a href="docs/screenshots/server-detail.webp"><img src="docs/screenshots/server-detail.webp" alt="Карточка сервера с графиками метрик" width="49%"></a>
</p>
<p align="center">
  <a href="docs/screenshots/groups.webp"><img src="docs/screenshots/groups.webp" alt="Управление группами серверов" width="49%"></a>
  <a href="docs/screenshots/notification-settings.webp"><img src="docs/screenshots/notification-settings.webp" alt="Настройки SMTP, Telegram и proxy" width="49%"></a>
</p>
```

- [ ] **Step 5: Run the focused contract**

Run `composer test -- tests/Contract/DocumentationContractTest.php --filter testReadmeReferencesCompleteLocalScreenshotGallery`.

Expected: pass with four recognized WebP assets and all matching README links.

- [ ] **Step 6: Commit the gallery**

Run `git add README.md docs/screenshots tests/Contract/DocumentationContractTest.php && git commit -m "docs: add dashboard screenshots"`.

### Task 3: Validate local and hosted documentation coverage

**Files:**
- Verify: `LICENSE`, `README.md`, `docs/screenshots/*.webp`
- Verify: `tests/Contract/DocumentationContractTest.php`, `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: PHPUnit, Composer validation, file metadata, GitHub Actions.
- Produces: fresh local and hosted verification evidence.

- [ ] **Step 1: Run the complete local quality suite**

Run `composer test && composer analyse && composer validate --strict && composer audit && git diff --check`.

Expected: every command exits zero.

- [ ] **Step 2: Inspect image metadata and visual safety**

Run `file docs/screenshots/*.webp` and open every image locally. Confirm WebP format, non-zero dimensions, synthetic data only, and no visible secret input values.

- [ ] **Step 3: Publish and inspect CI**

Run `git push origin master`, then `gh run list --commit "$(git rev-parse HEAD)" --limit 10 --json name,status,conclusion,url` until the `CI` record is completed.

Expected: `CI` has conclusion `success` and `git status --short` is empty.
