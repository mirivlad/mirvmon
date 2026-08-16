# MirvMon audit log

MirvMon 0.4.20 keeps a separate append-only audit history for successful administrative mutations. The log records an actor snapshot, action, object reference, human-readable description, and sanitized metadata suitable for operational review.

## Append-only boundary

Application code exposes ordinary audit history as append-and-read only. Migration `018_audit_log.sql` installs a PostgreSQL trigger that rejects direct `UPDATE` and `DELETE`, so normal application/database writes cannot rewrite or remove an existing audit row.

The actor user ID intentionally has no foreign key to `users`. Username and role are stored as snapshots, so deleting a user cannot mutate historical audit rows through `ON DELETE` behavior.

Migration `019_audit_retention.sql` adds the only supported deletion path: `mirvmon_prune_audit_log(cutoff)`. That function enters a transaction-local retention mode before deleting rows older than the supplied cutoff. Direct `DELETE FROM audit_log` remains rejected by the append-only trigger.

## Secrets

Passwords, tokens, credentials, API/app keys, authorization values, cookies, SMTP secrets, Telegram bot tokens, and similarly named nested metadata keys are redacted by `AuditLogger` before insertion. Event builders should still prefer structural facts such as changed field names, counts, booleans, IDs, and target versions instead of credential values or free-form sensitive text.

## Retention

Audit retention is deliberately independent from metrics/process retention and is configured on the admin **Audit log** page through `audit_retention_days`.

- `0` means keep audit history forever and is the default.
- A finite policy accepts 30–3650 days.
- `bin/audit-retention-worker` checks the policy independently of TimescaleDB metric/process retention and prunes only audit rows older than the configured cutoff.
- The worker is supervised separately and defaults to checking once per hour. `AUDIT_RETENTION_CHECK_INTERVAL` may be set from 300 to 86400 seconds.

Changing metric/process retention never changes audit retention, and changing audit retention never alters the TimescaleDB retention policies.
