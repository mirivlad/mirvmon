# Stale Agent Update Recovery Design

## Problem

Linux agent v0.4.5 can retain an `awaiting_restart` local state after it has
successfully installed the same version. That stale state makes its update
store reject a later command as already in progress. The later server command
therefore remains `pending`: the agent never reports even `accepted`.

When the monitoring container is subsequently upgraded again, the server also
keeps that old `pending` command active. `commandForServer()` refuses to return
it because its target no longer matches the current artifact catalog, while the
active-command uniqueness constraint prevents creation of a replacement.

## Server behavior

On an authenticated agent config poll, MirvMon terminalizes an active command
only when all of these conditions hold:

- the command is still `pending`, so the agent has not acknowledged it;
- its target version differs from the current artifact catalog version;
- its artifact key is still present in the current allowlisted catalog.

The repository locks the exact active row and changes it to `failed` with
`target_superseded`. It deliberately does not create a replacement: an agent
can persist local `accepted` before its HTTP report reaches the server, so a new
UUID could be rejected as another update already in progress. After local
recovery, the administrator explicitly requests the current update in the UI.

Commands in `accepted`, `downloading`, `installing`, or `awaiting_restart` are
never terminalized automatically. Repeated and concurrent polls idempotently
leave the command in the same `failed/target_superseded` state.

## Fleet recovery

The server-side fix releases the blocked database command, but v0.4.5 still
needs a one-time, recoverable cleanup of its stale local update files. Stop the
agent, update path, and one-shot updater, move the update coordination files
into a timestamped root-only backup directory, then start the path unit and
agent again. The administrator can then click «Повторить обновление» for the
current release.

No permanent agent token is changed, and no inbound connection to the monitored
host is introduced.

## Verification

- Repository integration tests prove locked terminalization, audit state,
  idempotency, and refusal to change an acknowledged command.
- Service integration tests prove an old `pending` target is terminalized
  without issuing a potentially unsafe new UUID.
- Existing PHP unit, integration, static analysis, Composer validation, and
  dependency audit checks remain green.
