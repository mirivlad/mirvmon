# Server UI and Notification Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Deliver a separate notification queue, a server Agent tab, an OS-aware searchable server list, consistent action controls, and verified v0.4.1 publication.

**Architecture:** Slim controllers normalize HTTP requests and render Twig; repositories validate filter scopes and execute parameterized PostgreSQL queries. Existing agent-token POST endpoints remain the only credential mutation path, while the server detail controller supplies token state to a dedicated Agent partial.

**Tech Stack:** PHP 8.5, Slim 4, Twig 3, PostgreSQL 17/TimescaleDB, Bootstrap 5, Font Awesome, vanilla JavaScript, PHPUnit, GitHub Actions.

## Global Constraints

- Retain existing Slim, Twig, Bootstrap, CSRF, session, and administrator-role protection.
- Keep notification settings at /admin/notifications; queue administration is administrator-only at /admin/notifications/queue.
- Every queue deletion is a CSRF-protected POST and can target every status, including pending and processing.
- Escape errors and pretty-print only stored payload JSON; never render notification secrets, permanent agent tokens, encrypted values, or raw exception traces.
- Installer credentials remain one-time; Agent UI exists only on the server detail page, never in the edit form.
- Preserve the existing x64 agent matrix and do not move the v0.4.0 tag.
- Do not modify the primary worktree's user-owned untracked plan.

---

## File Structure

| File | Responsibility |
| --- | --- |
| src/Repositories/NotificationOutboxRepository.php | Validate scopes; list/count/retry/delete outbox jobs with bound SQL. |
| src/Controllers/AdminController.php | Render queue filters, confirm destructive scope, and preserve queue query state. |
| src/Application/AppFactory.php | Route queue GET/POST actions under existing middleware. |
| templates/admin/notification-queue.twig | Render filters, rows, detail modals, pagination and bulk controls. |
| templates/partials/action-button.twig | Shared accessible icon-only list/table action control. |
| src/Controllers/ServerController.php | Server-list querying and filter/sort validation. |
| src/Controllers/ServerDetailController.php and src/Application/Bootstrap.php | Agent-tab token state. |
| templates/servers/index.twig, detail.twig, edit.twig, created.twig, partials/agent-management.twig | Server table and Agent-tab move. |
| templates/groups/index.twig, groups/show.twig, alerts/index.twig, admin/users.twig | Convert scoped actions to the shared icon control. |
| public/js/app.js | Initialize Bootstrap tooltips and debounced list filtering. |
| tests/Integration/Controllers/AdminNotificationControllerTest.php | Queue lifecycle and safe output. |
| tests/Integration/Controllers/ServerControllerTest.php and DashboardReadModelTest.php | Agent tab, server list query/data behavior. |
| tests/Contract/RouteSecurityContractTest.php and TemplateSecurityContractTest.php | POST/CSRF and template invariants. |
| .github/workflows/ci.yml | Disable VCS stamping in the first Windows cross-build. |

### Task 1: Add filtered outbox repository methods

**Files:**
- Modify: src/Repositories/NotificationOutboxRepository.php:437-525
- Test: tests/Integration/Controllers/AdminNotificationControllerTest.php

**Interfaces:**
- Produces filters(array $input): array{statuses:list<string>,channel:?string,server_id:?int,from:?string,to:?string,error:?string}.
- Produces page(array $filters, int $page, int $perPage = 25): array{jobs:list<array<string,mixed>>,total:int,pages:int}, countMatching(array $filters): int, retryByIds(list<int> $ids): int, deleteByIds(list<int> $ids): int, deleteMatching(array $filters): int.

- [ ] **Step 1: Write failing integration coverage for filtering and destructive scope**

~~~php
public function testQueueFiltersAndDeletesOnlyMatchingJobs(): void
{
    $deadId = $this->seedQueueJob('email', 'dead', 'smtp_timeout', ['message' => 'safe']);
    $pendingId = $this->seedQueueJob('telegram', 'pending', null, ['token' => 'not-rendered']);
    $filters = $this->outbox->filters(['status' => ['dead'], 'channel' => 'email', 'error' => 'timeout']);
    self::assertSame([$deadId], array_column($this->outbox->page($filters, 1)['jobs'], 'id'));
    self::assertSame(1, $this->outbox->deleteMatching($filters));
    self::assertNotNull($this->job($pendingId));
}
~~~

- [ ] **Step 2: Run the focused test to verify failure**

Run: composer test -- --filter AdminNotificationControllerTest

Expected: FAIL because filters(), page(), and deleteMatching() are absent.

- [ ] **Step 3: Implement the bounded, parameterized API**

~~~php
$allowedStatuses = ['pending', 'processing', 'sent', 'failed', 'dead'];
$statuses = array_values(array_intersect($allowedStatuses, $input['status'] ?? []));
return [
    'statuses' => $statuses,
    'channel' => in_array($input['channel'] ?? null, ['email', 'telegram'], true) ? $input['channel'] : null,
    'server_id' => filter_var($input['server'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
    'from' => $this->dateBoundary($input['from'] ?? null, false),
    'to' => $this->dateBoundary($input['to'] ?? null, true),
    'error' => $this->substring($input['error'] ?? null),
];
~~~

Implement one whereClause() used by list, count, and delete; bind each status separately; constrain error text to 200 characters and escape percent, underscore, and backslash for ILIKE ... ESCAPE. Select payload::text AS payload_json, decode it, and expose payload_pretty built with JSON_PRETTY_PRINT and JSON_UNESCAPED_UNICODE. Order by jobs.id DESC, cap pages to 25 rows, join server names, and return total/pages separately.

- [ ] **Step 4: Run the focused test to verify passing repository behavior**

Run: composer test -- --filter AdminNotificationControllerTest

Expected: new repository assertions PASS; old settings-page queue assertion can remain failing until Task 3.

- [ ] **Step 5: Commit**

Run: git add src/Repositories/NotificationOutboxRepository.php tests/Integration/Controllers/AdminNotificationControllerTest.php && git commit -m "feat: add filtered notification outbox queries"

### Task 2: Add queue routes and controller workflow

**Files:**
- Modify: src/Application/AppFactory.php:186-202
- Modify: src/Controllers/AdminController.php:187-251
- Modify: tests/Contract/RouteSecurityContractTest.php:31-56
- Test: tests/Integration/Controllers/AdminNotificationControllerTest.php

**Interfaces:**
- Consumes Task 1 repository methods and existing auth, admin, and CSRF middleware.
- Produces notificationQueue(), retryNotificationJob(), deleteNotificationJob(), deleteNotificationQueue(), and a query-preserving queueLocation() helper.

- [ ] **Step 1: Write failing POST-only and confirmation tests**

~~~php
self::assertSame(['GET'], $routes['/admin/notifications/queue'] ?? null);
self::assertSame(['POST'], $routes['/admin/notifications/queue/retry'] ?? null);
self::assertSame(['POST'], $routes['/admin/notifications/queue/{id}/retry'] ?? null);
self::assertSame(['POST'], $routes['/admin/notifications/queue/{id}/delete'] ?? null);
self::assertSame(['POST'], $routes['/admin/notifications/queue/delete'] ?? null);

$response = $this->controller->deleteNotificationQueue(
    $this->request('POST', '/admin/notifications/queue/delete', ['status' => ['pending']]),
    (new ResponseFactory())->createResponse(), []
);
self::assertSame(302, $response->getStatusCode());
self::assertNotNull($this->job($pendingId));
~~~

- [ ] **Step 2: Run focused tests to verify failure**

Run: composer test -- --filter 'RouteSecurityContractTest|AdminNotificationControllerTest'

Expected: FAIL because routes/actions are missing.

- [ ] **Step 3: Register and implement the minimum protected actions**

~~~php
$group->get('/notifications/queue', self::controller($container, AdminController::class, 'notificationQueue'));
$group->post('/notifications/queue/retry', self::controller($container, AdminController::class, 'retryNotificationQueue'));
$group->post('/notifications/queue/{id}/retry', self::controller($container, AdminController::class, 'retryNotificationJob'));
$group->post('/notifications/queue/{id}/delete', self::controller($container, AdminController::class, 'deleteNotificationJob'));
$group->post('/notifications/queue/delete', self::controller($container, AdminController::class, 'deleteNotificationQueue'));
~~~

notificationQueue() calls filters() on query params, normalizes page to at least one, and renders queue data, counts, worker heartbeats, and SELECT id, name FROM servers ORDER BY name, id. Bulk delete calls deleteMatching() only when confirm_delete equals 1; otherwise it flashes countMatching() and redirects with no mutation. Individual delete accepts a positive integer only. Individual retry changes only failed/dead rows; bulk retry keeps current failed/dead semantics. Every mutation uses queueLocation($filters, $page) generated with http_build_query().

- [ ] **Step 4: Run focused tests**

Run: composer test -- --filter 'RouteSecurityContractTest|AdminNotificationControllerTest'

Expected: PASS, including a confirmed deletion of a pending job and no deletion without confirmation.

- [ ] **Step 5: Commit**

Run: git add src/Application/AppFactory.php src/Controllers/AdminController.php tests/Contract/RouteSecurityContractTest.php tests/Integration/Controllers/AdminNotificationControllerTest.php && git commit -m "feat: add notification queue administration routes"

### Task 3: Render queue page, navigation, tooltips and shared action controls

**Files:**
- Create: templates/admin/notification-queue.twig
- Create: templates/partials/action-button.twig
- Modify: templates/admin/notifications.twig:280-378
- Modify: templates/layout.twig:70-83
- Modify: public/js/app.js:1-39
- Test: tests/Integration/Controllers/AdminNotificationControllerTest.php

**Interfaces:**
- Consumes queue data/actions from Task 2.
- Produces accessible icon-only action markup and queue HTML with filters, rows, pagination, modal details, and confirmed filter-wide deletion.

- [ ] **Step 1: Write failing render assertions**

~~~php
self::assertStringContainsString('name="status[]"', $queueHtml);
self::assertStringContainsString('action="/admin/notifications/queue/delete"', $queueHtml);
self::assertStringContainsString('confirm_delete', $queueHtml);
self::assertStringContainsString('data-bs-toggle="modal"', $queueHtml);
self::assertStringContainsString('/admin/notifications/queue', $layoutHtml);
self::assertStringNotContainsString('Последние 20 заданий', $settingsHtml);
~~~

- [ ] **Step 2: Run focused tests to verify failure**

Run: composer test -- --filter AdminNotificationControllerTest

Expected: FAIL because the separate template and navigation item do not exist.

- [ ] **Step 3: Build the views and initialization**

~~~twig
{% if form_action is defined %}<form method="post" action="{{ form_action }}" class="d-inline">{% include "partials/csrf.twig" %}{% endif %}
<{% if form_action is defined %}button type="submit"{% else %}a href="{{ href }}"{% endif %} class="btn btn-sm {{ class|default('btn-outline-secondary') }}" aria-label="{{ label }}" data-bs-toggle="tooltip" title="{{ label }}"{% if confirm is defined %} data-confirm="{{ confirm }}"{% endif %}><i class="fas {{ icon }}" aria-hidden="true"></i></{% if form_action is defined %}button{% else %}a{% endif %}>
{% if form_action is defined %}</form>{% endif %}
~~~

Queue filters are GET fields status[], channel, server, from, to, error. Preserve filters in retry/delete forms. The result table has created time, channel, recipient, event/server, status, attempts, sent time, and icon actions. Eye opens a server-rendered modal with escaped payload_pretty and last_error; retry appears only for failed/dead; delete posts an individual action with data-confirm. The bulk delete form submits filter values plus confirm_delete=1, says the matching count, and its confirm wording says selected statuses or all statuses. Move worker heartbeat display to this page. Replace the old settings queue card with a normal link. Add the Settings menu item directly after Уведомления.

~~~js
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
    bootstrap.Tooltip.getOrCreateInstance(element);
});
~~~

- [ ] **Step 4: Verify security and rendering**

Run: composer test -- --filter 'AdminNotificationControllerTest|TemplateSecurityContractTest'

Expected: PASS; all new POST forms contain partials/csrf.twig.

- [ ] **Step 5: Commit**

Run: git add templates/admin/notification-queue.twig templates/partials/action-button.twig templates/admin/notifications.twig templates/layout.twig public/js/app.js tests/Integration/Controllers/AdminNotificationControllerTest.php && git commit -m "feat: move notification outbox to its own page"

### Task 4: Move agent management to the server detail page

**Files:**
- Modify: src/Application/Bootstrap.php:217-225
- Modify: src/Controllers/ServerDetailController.php:29-113
- Modify: src/Controllers/ServerController.php:278-333
- Create: templates/servers/partials/agent-management.twig
- Modify: templates/servers/detail.twig:64-97
- Modify: templates/servers/edit.twig:171-213
- Modify: templates/servers/created.twig:86-95
- Test: tests/Integration/Controllers/ServerControllerTest.php
- Test: tests/Integration/Controllers/DashboardReadModelTest.php

**Interfaces:**
- Consumes agent_tokens.token_generation and existing installer/revoke POST routes.
- Produces detail view fields has_agent_token and requires_token_rotation; redirects Agent workflow failures to /servers/{id}.

- [ ] **Step 1: Write failing Agent-tab tests**

~~~php
$html = $this->renderDetail($serverIdWithLegacyToken);
self::assertStringContainsString('id="agent-tab"', $html);
self::assertStringContainsString('Для этого агента требуется явный отзыв ключа.', $html);
self::assertStringNotContainsString('Управление агентом мониторинга', $this->renderEdit($serverIdWithLegacyToken));
~~~

- [ ] **Step 2: Run focused tests to verify failure**

Run: composer test -- --filter 'ServerControllerTest|DashboardReadModelTest'

Expected: FAIL because detail data and Agent markup are absent.

- [ ] **Step 3: Implement token-state rendering and partial extraction**

~~~php
$statement = $this->pdo->prepare('SELECT token_generation FROM agent_tokens WHERE server_id = :server_id');
$statement->execute(['server_id' => $serverId]);
$tokenGeneration = $statement->fetchColumn();

'has_agent_token' => $tokenGeneration !== false,
'requires_token_rotation' => $tokenGeneration === null,
~~~

Inject PDO in ServerDetailController through Bootstrap. Add an Агент Bootstrap tab after current tabs, leaving Метрики default-active. Move the existing installer form, legacy warning and revoke/create modal into servers/partials/agent-management.twig, including CSRF. Delete that block from edit. Change failure/legacy redirects in installers() and regenerateToken() to detail; change the created screen's secondary link to detail.

- [ ] **Step 4: Verify**

Run: composer test -- --filter 'ServerControllerTest|DashboardReadModelTest|TemplateSecurityContractTest'

Expected: PASS; no agent controls remain in edit and every credential POST remains CSRF-protected.

- [ ] **Step 5: Commit**

Run: git add src/Application/Bootstrap.php src/Controllers/ServerDetailController.php src/Controllers/ServerController.php templates/servers/detail.twig templates/servers/partials/agent-management.twig templates/servers/edit.twig templates/servers/created.twig tests/Integration/Controllers/ServerControllerTest.php tests/Integration/Controllers/DashboardReadModelTest.php && git commit -m "feat: manage agents from server detail page"

### Task 5: Implement correct, sortable, debounced server list

**Files:**
- Modify: src/Controllers/ServerController.php:26-45
- Modify: templates/servers/index.twig:14-75
- Modify: public/js/app.js:39-75
- Test: tests/Integration/Controllers/ServerControllerTest.php

**Interfaces:**
- Consumes query keys sort, direction, name, address, group, description, last_metrics.
- Produces servers, filters, sort, direction; data-server-filter-form submits GET 350 ms after typing ends.

- [ ] **Step 1: Write failing server-list behavior tests**

~~~php
$html = $this->renderIndex('/servers?sort=address&direction=asc&name=Alpha');
self::assertStringContainsString('fa-linux', $html);
self::assertStringContainsString('title="Linux 6.8"', $html);
self::assertStringContainsString('red-group-icon', $html);
self::assertStringNotContainsString('>Zulu<', $html);
~~~

- [ ] **Step 2: Run focused test to verify failure**

Run: composer test -- --filter ServerControllerTest

Expected: FAIL because the current query only joins group_name and ignores URL values.

- [ ] **Step 3: Implement a strict SQL/data and Twig contract**

~~~php
$sortColumns = [
    'name' => 'servers.name', 'address' => 'servers.address',
    'group' => 'server_groups.name', 'description' => 'servers.description',
    'last_metrics' => 'servers.last_metrics_at',
];
$sort = is_string($query['sort'] ?? null) && isset($sortColumns[$query['sort']]) ? $query['sort'] : 'name';
$direction = ($query['direction'] ?? null) === 'desc' ? 'DESC' : 'ASC';
~~~

Select per-row group_icon, group_color, and servers.os_version; build one bound ILIKE predicate per nonempty, escaped, 100-character search term; append whitelisted sort SQL plus NULLS LAST, servers.id ASC. Add sortable headers that preserve filters and one type="search" field per specified column. Render OS beside server name: fab fa-windows for values starting Windows, fab fa-linux for values starting Linux, otherwise fas fa-server; tooltip and aria-label are exact OS version or ОС не сообщена. Use each selected row's group_icon/group_color, eliminating shared group state.

~~~js
document.querySelectorAll('[data-server-filter-form]').forEach((form) => {
    let timeoutId;
    form.querySelectorAll('input[type="search"]').forEach((input) => input.addEventListener('input', () => {
        window.clearTimeout(timeoutId);
        timeoutId = window.setTimeout(() => form.requestSubmit(), 350);
    }));
});
~~~

- [ ] **Step 4: Verify**

Run: composer test -- --filter ServerControllerTest && node --check public/js/app.js

Expected: PASS; different groups retain their own icon/color, invalid sort falls back to name ascending, and matching search filters output.

- [ ] **Step 5: Commit**

Run: git add src/Controllers/ServerController.php templates/servers/index.twig public/js/app.js tests/Integration/Controllers/ServerControllerTest.php && git commit -m "feat: add searchable sortable server list"

### Task 6: Apply shared icon-only actions everywhere in scope

**Files:**
- Modify: templates/servers/index.twig
- Modify: templates/groups/index.twig
- Modify: templates/groups/show.twig
- Modify: templates/alerts/index.twig
- Modify: templates/admin/users.twig
- Modify: templates/servers/detail.twig
- Test: tests/Contract/TemplateSecurityContractTest.php

**Interfaces:**
- Consumes templates/partials/action-button.twig and existing route/form values.
- Produces consistent view/edit/delete/resolve/retry/cancel action controls with aria-label, tooltip and no visible text.

- [ ] **Step 1: Write failing partial-use and accessibility assertions**

~~~php
$serverTemplate = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/servers/index.twig');
$partial = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/partials/action-button.twig');
self::assertStringContainsString('partials/action-button.twig', $serverTemplate);
self::assertStringContainsString('aria-label="{{ label }}"', $partial);
self::assertStringContainsString('data-bs-toggle="tooltip"', $partial);
self::assertStringNotContainsString('d-none d-sm-inline', $serverTemplate);
~~~

- [ ] **Step 2: Run the contract test to verify failure**

Run: composer test -- --filter TemplateSecurityContractTest

Expected: FAIL because pre-existing action captions remain.

- [ ] **Step 3: Replace scoped controls without changing mutation semantics**

~~~twig
{% include 'partials/action-button.twig' with {href: '/servers/' ~ server.id, icon: 'fa-eye', label: 'Просмотр', class: 'btn-outline-info'} %}
{% include 'partials/action-button.twig' with {form_action: '/servers/' ~ server.id ~ '/delete', icon: 'fa-trash', label: 'Удалить', class: 'btn-outline-danger', confirm: 'Вы уверены, что хотите удалить этот сервер?'} %}
~~~

Use the same partial for group view/edit/delete, alert resolve, user edit/delete, queue actions and compact detail-header actions. Keep large create/save/form-submit controls readable. Preserve user edit data-user attributes and all destructive data-confirm text; no JavaScript action endpoint is introduced.

- [ ] **Step 4: Verify**

Run: composer test -- --filter 'TemplateSecurityContractTest|ServerControllerTest|AdminNotificationControllerTest'

Expected: PASS; each mutation stays POST with server-generated CSRF fields.

- [ ] **Step 5: Commit**

Run: git add templates/servers/index.twig templates/groups/index.twig templates/groups/show.twig templates/alerts/index.twig templates/admin/users.twig templates/servers/detail.twig tests/Contract/TemplateSecurityContractTest.php && git commit -m "style: unify icon-only action buttons"

### Task 7: Correct CI VCS stamping

**Files:**
- Modify: .github/workflows/ci.yml:116-118

**Interfaces:**
- Produces two Windows amd64 build commands that both pass -buildvcs=false.

- [ ] **Step 1: Verify the current workflow has the failing command**

Run: rg -n 'GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build ./...' .github/workflows/ci.yml

Expected: exactly one match without -buildvcs=false.

- [ ] **Step 2: Apply the one-line fix**

~~~yaml
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -buildvcs=false ./...
~~~

- [ ] **Step 3: Verify the Go gate locally**

Run: cd agent && go test ./... && go test -race ./... && GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -buildvcs=false ./...

Expected: all three commands exit zero.

- [ ] **Step 4: Commit**

Run: git add .github/workflows/ci.yml && git commit -m "ci: disable Go VCS stamping in cross-build"

### Task 8: Verify release candidate and publish v0.4.1

**Files:**
- Modify only a file from Tasks 1-7 if a check identifies a scoped defect.

**Interfaces:**
- Consumes the completed feature commits and CI workflow.
- Produces a pushed master, annotated v0.4.1, green CI including publication, and GHCR multi-arch manifest.

- [ ] **Step 1: Run all local gates**

Run: composer test && composer analyse && composer validate --strict && composer audit && npm ci && npm run assets:sync && git diff --exit-code -- public/vendor && npm audit --audit-level=high && shellcheck docker/*.sh && docker compose --env-file .env -f docker/docker-compose.yml config --quiet && docker build -f docker/Dockerfile .

Expected: all commands exit zero. If TEST_DB_* is unavailable, explicitly record that integration tests were skipped rather than calling the PHP run complete.

- [ ] **Step 2: Perform health and browser smoke verification**

Run: docker compose --env-file .env -f docker/docker-compose.yml -f docker/docker-compose.build.yml up -d --build && curl --fail --silent --show-error http://127.0.0.1:8080/livez && curl --fail --silent --show-error http://127.0.0.1:8080/readyz

Expected: both health checks succeed. In an authenticated browser, test desktop and 390px queue navigation/filter/detail/delete confirmation, server tabs, sort/search debounce, OS/group icon distinction, tooltips, and zero console errors. Stop the stack that this step started using the matching docker compose down command.

- [ ] **Step 3: Inspect clean diff, merge safely, tag and push**

Run: git diff --check && git status --short && git fetch origin && git log --oneline origin/master..HEAD

Expected: no whitespace errors and only intended commits. Merge into updated master, push it, then run git tag -a v0.4.1 -m 'MirvMon 0.4.1' && git push origin v0.4.1. Do not force-push or change v0.4.0.

- [ ] **Step 4: Verify GitHub Actions and the published manifest**

Run: gh run list --repo mirivlad/mirvmon --workflow CI --limit 5 && gh run watch <v0.4.1-run-id> --repo mirivlad/mirvmon --exit-status && docker buildx imagetools inspect ghcr.io/mirivlad/mirvmon:0.4.1

Expected: PHP, Frontend assets, both Agent / Go jobs, Container and Compose, and Publish multi-arch image all conclude success; the manifest includes linux/amd64 and linux/arm64.
