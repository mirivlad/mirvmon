# Server UI and Notification Queue Design

## Goal

Improve administration and server-management UI: manage the notification
outbox on its own page, move agent credential actions to a server tab, show
the operating system in server lists, repair group icon/color rendering, and
make the server table searchable, sortable, and consistent in its actions.

## Scope and constraints

- Retain the existing Slim, Twig, Bootstrap, CSRF, session, and role checks.
- Keep notification settings at `/admin/notifications`; queue management is a
  separate administrator-only page at `/admin/notifications/queue`.
- The queue page may delete jobs in every status. Deletion is always scoped to
  the currently submitted filters and requires an explicit confirmation
  containing the number of affected jobs and selected statuses.
- Never render notification secrets, encrypted transport settings, agent
  tokens, or raw exception internals. Payloads are rendered as escaped,
  pretty-printed JSON. The existing `last_error` is rendered as escaped text.
- Agent management belongs only to the new `Агент` tab on the server detail
  page. Server editing has no installer-download or credential-revocation UI.
- Continue to use one-time installer credentials. The permanent agent token
  must never appear in a browser URL or HTML.
- Use x64 agent copy already established by the v0.4.0 work; this UI task does
  not alter agent protocol or support matrix.
- No changes to user-owned untracked files in the primary worktree.

## Administration navigation and notification outbox

`templates/layout.twig` gains an `Очередь уведомлений` navigation item directly
after `Уведомления` in the Settings submenu. It is visible only to
administrators, alongside the existing notification settings link.

`AdminController::notificationSettings()` stops querying/rendering the outbox;
the settings page retains transport configuration, test-delivery UI, worker
health, and a link to the queue.

The new GET `/admin/notifications/queue` accepts stable query parameters:

- `status[]`: one or more outbox statuses;
- `channel`: `telegram`, `email`, or empty;
- `server`: positive server ID or empty;
- `from` and `to`: ISO date boundaries;
- `error`: substring matched against the stored error text;
- `page`: positive page number.

The page shows a bounded, newest-first result list and pagination. Its filter
form keeps the selected values in query parameters. The repository owns the
filter validation and parameterized SQL, returning both results and a count.
The controller only normalizes HTTP input and renders it.

Each row offers icon-only actions: view details, retry the individual task when
appropriate, and delete the individual task. A details modal obtains the
row's preloaded escaped data attributes or server-rendered modal content; it
shows no transport credentials. Bulk retry retains the existing semantics for
undelivered tasks. Bulk delete posts the filter set plus an explicit
`confirm_delete=1`; without that confirmation the controller renders/reloads a
confirmation modal rather than deleting. A clear-all operation is therefore
represented by intentionally selecting no filters and confirming the resulting
count.

## Server detail: Agent tab

`ServerDetailController::show()` supplies the same server record and token
state needed by agent actions. `templates/servers/detail.twig` gains Bootstrap
tabs, preserving the current monitoring content as the default tab and adding
`Агент`.

The Agent tab is extracted to `templates/servers/partials/agent-management.twig`
because it owns a cohesive UI: explanation of credential behaviour, the
installer-download form, the legacy-key warning, and the revoke/create-key
confirmation modal. Existing POST routes and their authorization/CSRF rules
remain unchanged. `templates/servers/edit.twig` removes all agent-management
markup; it only edits server settings.

## Server table and OS presentation

`ServerController::index()` obtains the exact OS version already persisted on
the server. The server table renders a Linux icon for values beginning with a
Linux-family operating-system name, a Windows icon for values beginning with
`Windows`, and a neutral server icon for absent/unknown data. Each has a
Bootstrap tooltip containing the escaped full OS version, or `ОС не сообщена`.

The existing group presentation bug is fixed at the query/data-shaping
boundary: every result row carries its own selected group icon and color, and
the Twig table uses those row fields without leaking state from another row.

The list supports sorting by the visible columns `name`, `address`, `group`,
`description`, and `last_metrics`. `ServerController` accepts a strict sort
key and direction whitelist and applies a deterministic secondary order by
server ID. The server list gets one search input per those columns. Typing
uses a short debounced GET update to the query string; the same terms are also
processed server-side using parameterized expressions so pagination and direct
links remain correct. Search values are escaped and limited before query use.

## Action buttons and accessibility

Create a small Twig partial for table/list action buttons. It produces compact
buttons with a Font Awesome icon, `aria-label`, Bootstrap tooltip attributes,
and no visible action caption. Callers select only the established semantic:
view, edit, delete, resolve, retry, or cancel. Destructive forms retain CSRF
tokens and confirmation logic. This partial replaces the affected action
buttons in servers, groups, alerts, users, and queue views while leaving large
primary form-submission buttons readable.

## Testing and verification

- Unit tests cover outbox filter/selection validation, safe count/delete
  scope, and allowed sorting keys/directions.
- Integration controller tests cover administrator-only queue routes, filter
  preservation, bulk-delete confirmation, individual queue operations, and
  server agent-tab rendering without agent controls in edit HTML.
- Functional/render tests assert OS tooltip/icon mapping, per-row group icon
  and color values, sortable/searchable table markup, and accessible
  icon-only action controls.
- Browser smoke tests exercise desktop and 390px navigation, queue filters and
  confirmation, server tabs, table sorting/search debounce, and browser
  console errors.
- Run the PHP suite against clean TimescaleDB, static analysis, Composer
  validation/audit, frontend asset/audit checks, ShellCheck, Compose config,
  production image build, then the release-tag GitHub Actions workflow.

## Release sequence

Fix the Go CI cross-build command by adding `-buildvcs=false`, commit it with
the UI work, push the resulting master merge, and create a new annotated
`v0.4.1` tag. Do not move or overwrite the failed `v0.4.0` tag. Verify all CI
jobs and the publish job, then inspect the GHCR manifest for the `0.4.1` image
and its linux/amd64 and linux/arm64 platforms.
