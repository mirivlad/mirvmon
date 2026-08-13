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

On an authenticated agent config poll, MirvMon replaces an active command only
when all of these conditions hold:

- the command is still `pending`, so the agent has not acknowledged it;
- its target version differs from the current artifact catalog version;
- its artifact key is still present in the current allowlisted catalog.

The repository performs the replacement in one transaction while locking the
active row. The old row becomes `failed` with `target_superseded`; the new row
keeps `server_id`, `target_artifact`, and `requested_by`, receives the current
catalog version and a new UUID, and starts in `pending`.

Commands in `accepted`, `downloading`, `installing`, or `awaiting_restart` are
never replaced automatically. Repeated and concurrent polls return the single
replacement command and do not create multiple active rows.

## Fleet recovery

The server-side fix releases the blocked database command, but v0.4.5 still
needs a one-time, recoverable cleanup of its stale local update files. Stop the
agent and update path unit, move the update coordination files into a timestamped
root-only backup directory, then start the path unit and agent again. The next
config poll receives the replacement command for the current release.

No permanent agent token is changed, and no inbound connection to the monitored
host is introduced.

## Verification

- Repository integration tests prove transactional replacement, audit state,
  idempotency, and refusal to replace an acknowledged command.
- Service integration tests prove an old `pending` target is replaced and
  delivered from the current allowlisted catalog.
- Existing PHP unit, integration, static analysis, Composer validation, and
  dependency audit checks remain green.

