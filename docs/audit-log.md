# MirvMon audit log

MirvMon 0.4.20 keeps a separate append-only audit history for successful administrative mutations. The log records an actor snapshot, action, object reference, human-readable description, and sanitized metadata suitable for operational review.

## Append-only boundary

Application code exposes only append and read operations for `audit_log`. Migration `018_audit_log.sql` also installs a PostgreSQL trigger that rejects `UPDATE` and `DELETE`, so ordinary application/database writes cannot rewrite an existing audit row.

The actor user ID intentionally has no foreign key to `users`. Username and role are stored as snapshots, so deleting a user cannot mutate historical audit rows through `ON DELETE` behavior.

## Secrets

Passwords, tokens, credentials, API/app keys, authorization values, cookies, SMTP secrets, Telegram bot tokens, and similarly named nested metadata keys are redacted by `AuditLogger` before insertion. Event builders should still prefer structural facts such as changed field names, counts, booleans, IDs, and target versions instead of credential values or free-form sensitive text.

## Retention

Audit retention is deliberately independent from metrics/process retention. In 0.4.20 there is **no automatic audit purge or retention job**: audit rows remain until a future, explicitly designed audit-retention policy is introduced. TimescaleDB metric/process retention policies do not target `audit_log`.

This separation is intentional: shortening metric history must never silently shorten the administrative audit trail.
