# Backup & Disaster Recovery — v0.6.0

This document is the implementation contract for the `v0.6.0` disaster-recovery feature.
It intentionally covers only full-instance backup/restore. Configuration-only backup remains
future work.

## Recovery goal

The supported disaster-recovery scenario is:

1. installation A runs with `APP_KEY=A`;
2. A creates a password-protected full backup;
3. A is lost;
4. a clean installation B is deployed with a different `APP_KEY=B`;
5. the operator completes `/setup` and signs in with a temporary administrator;
6. the backup is uploaded and preflighted without database writes;
7. B enters controlled maintenance mode and restores the backup;
8. restored application secrets are re-encrypted under `APP_KEY=B`;
9. permanent server identity and agent credentials remain valid;
10. the temporary B session is destroyed and the operator signs in with an account restored from A;
11. an already installed agent using its old permanent credential successfully submits metrics to B.

For seamless recovery of already-installed agents, B MUST become reachable at the same public
service URL that those agents already have in `api_url` and `config_url`. In practice the old DNS
name or IP/URL is moved to B. Changing endpoint discovery is outside v0.6.0.

## Backup contents

The v0.6.0 full backup contains the complete MirvMon PostgreSQL/TimescaleDB database, including
metrics history, website history, incidents, audit records, notification state, users, groups,
servers, agent metadata, application settings and schema migration state.

The backup MUST NOT contain the source installation `APP_KEY`.

Application secrets that are stored encrypted under `APP_KEY` are exported through an explicit
secret catalog, decrypted on A, protected by the backup encryption context and re-encrypted under
B during restore. At minimum this includes notification secrets and website credentials/secret
headers.

Runtime-local state such as PHP sessions, temporary restore workspaces and caches is not part of
the backup.

## Agent identity and installer behavior

The database remains authoritative for permanent agent authentication: `agent_tokens.token_hash`
and `token_generation` are restored unchanged. Existing agents therefore continue to authenticate
with the permanent token already stored on the monitored host.

MirvMon does not add a recoverable plaintext copy of permanent agent tokens in v0.6.0.

Permanent tokens are currently derived from `APP_KEY + server_id + token_generation` when an
installer is generated/exchanged. After restore under a different `APP_KEY`, the restored hash may
not be reproducible by the new installation. MirvMon MUST detect this condition before issuing a
new installer for that server and require an explicit token regeneration instead of producing an
installer that cannot authenticate.

Operational rule after disaster recovery:

- already installed agents keep using their existing permanent credentials;
- previously downloaded installers are not part of the DR compatibility guarantee;
- to reinstall an agent after restore, explicitly regenerate that server's agent credential and
  download a fresh installer;
- regenerating a credential intentionally invalidates the old permanent agent token.

Unconsumed temporary installer credentials from A are invalidated during restore. They are not
permanent agent identity and must not be resurrected on B.

## Backup container v1

The backup uses a versioned MirvMon container rather than an arbitrary user-controlled archive
layout.

Plaintext header contains only information required to unlock the container:

- magic and container format version;
- KDF/crypto algorithm identifiers;
- KDF parameters and salt;
- wrapped random data-key metadata.

The encrypted payload contains fixed records:

- `manifest`;
- `database.pgdump`;
- `secrets.json`.

The manifest records at least:

- backup UUID and creation time;
- source MirvMon version/commit when available;
- backup type (`full`);
- PostgreSQL version/server version number;
- TimescaleDB version;
- source `schema_migrations` names/checksums;
- secret payload version;
- record sizes and SHA-256 digests;
- small operator-facing statistics such as server/site counts and metric time range when cheap to
  collect.

The manifest is encrypted with the payload so that infrastructure metadata is not exposed merely
by possessing the backup file.

## Backup encryption

The backup password is independent of `APP_KEY`.

Container v1 uses libsodium primitives:

- Argon2id (`sodium_crypto_pwhash`) to derive a key-encryption key from the backup password;
- a random 256-bit data-encryption key per backup;
- XChaCha20-Poly1305 to wrap the data key;
- `secretstream_xchacha20poly1305` for streaming encrypted payload records.

KDF parameters stored in the header have implementation-defined upper bounds so a malicious file
cannot request unbounded CPU or memory during preflight.

Temporary files use restrictive permissions and are removed on both success and failure.

## Consistent backup creation

Creating a backup does not require maintenance mode.

To keep the database dump and exported secret catalog at one database snapshot:

1. open a read-only `REPEATABLE READ` transaction;
2. call `pg_export_snapshot()`;
3. read secret/catalog/schema/version metadata from that snapshot;
4. run `pg_dump -Fc --snapshot=<snapshot>` against the full database;
5. close the snapshot transaction only after `pg_dump` completes;
6. build manifest/checksums;
7. encrypt to a temporary output file;
8. atomically rename the completed backup.

Backup creation fails closed when a registered application secret cannot be decrypted. MirvMon
must not knowingly emit a backup that cannot later restore its protected configuration.

## Restore preflight

Preflight may write only to the dedicated restore workspace. It MUST NOT mutate the MirvMon
database.

It validates, in order:

1. magic and supported container version;
2. bounded KDF parameters;
3. password/AEAD authentication;
4. allowed record structure;
5. manifest schema;
6. record sizes and checksums;
7. PostgreSQL custom dump readability with `pg_restore --list`;
8. `backup_type=full`;
9. source migration/checksum compatibility with the current application;
10. PostgreSQL/TimescaleDB compatibility information;
11. completeness and version of the secret payload.

A successful preflight presents the source MirvMon, PostgreSQL and TimescaleDB versions and asks
for explicit restore confirmation.

## Cross-version policy

Disaster recovery must not depend on the operator remembering the destroyed installation's exact
software versions. Those versions are stored in the backup manifest.

By default MirvMon attempts restore onto the current supported stack when the source PostgreSQL or
TimescaleDB patch/extension version differs. A version mismatch is therefore a preflight warning,
not an automatic rejection, unless the direction is known to be impossible or unsafe.

If the staged restore fails because of database/TimescaleDB incompatibility, the live B database
is left untouched. Troubleshooting MUST show the source versions recorded in the backup and advise
restoring first on a matching MirvMon/PostgreSQL/TimescaleDB stack, then upgrading through normal
MirvMon upgrade/migration procedures.

A backup filename should include the source MirvMon version and timestamp for operator convenience,
but the encrypted manifest is authoritative.

## Maintenance and quiescence

Restore state is installation-local and MUST NOT be stored only inside the database that is about
to be replaced.

The implementation uses `/app/var/dr` for operation state and a filesystem maintenance marker/lock.
During restore:

- metrics ingestion returns `503 Service Unavailable` with `Retry-After`;
- authenticated agent config/update endpoints return `503`;
- mutating administrator actions outside the DR flow are blocked;
- `/livez` remains usable;
- readiness may report unavailable;
- workers finish current bounded work, then pause before beginning new database work.

A shared/exclusive filesystem lock is used to close the race between checking the maintenance flag
and beginning database work: normal mutating work holds a shared lock, restore sets the marker and
waits for the exclusive lock before touching the database.

The agent HTTP transport already treats `503` as retryable, so queued metrics remain on the agent
and are sent after maintenance ends.

## Staged restore and cutover

Restore is never performed destructively into the currently running B database.

After successful preflight and explicit confirmation:

1. write maintenance marker;
2. acquire exclusive DR lock;
3. create a staging database, e.g. `mirvmon_restore_<uuid>`;
4. create/prepare TimescaleDB as required;
5. call `timescaledb_pre_restore()`;
6. restore with `pg_restore --no-owner --no-privileges --exit-on-error`;
7. call `timescaledb_post_restore()`;
8. compare restored `schema_migrations` with the encrypted manifest;
9. run the normal MirvMon `Migrator` against the staging database to apply supported forward
   migrations;
10. re-encrypt registered application secrets under B's `APP_KEY`;
11. normalize installation/process-local state;
12. run integrity checks and `ANALYZE`;
13. only then cut over database names/connections;
14. smoke-test the restored primary database;
15. roll back the database-name cutover if the smoke test fails;
16. clear all PHP sessions;
17. release maintenance and redirect to login.

A failed staging restore is discarded and leaves B's live database unchanged.

## Post-restore normalization

The full dump is preserved except for state that is unsafe or meaningless to resurrect on a new
installation. At minimum restore normalizes:

- `login_attempts` (source hashes depend on the old `APP_KEY`);
- `worker_heartbeats`;
- process leases/claims that belong to the old installation;
- notification rows left in a transient `processing` state;
- all unconsumed temporary `installer_tokens`.

Permanent `agent_tokens`, server IDs, metrics/history, website history, incidents, audit history,
maintenance windows and durable configuration remain intact.

## Web/UI flow

Backup/Restore lives under the System administration area.

Restore is explicitly two-stage:

1. upload backup + password and run preflight;
2. show source versions/summary/warnings and require an explicit destructive Restore confirmation.

Long-running restore operations use filesystem-backed operation state under `/app/var/dr` and a
dedicated supervised `dr-worker`, so the browser request that confirms restore only queues work.
The worker owns staging restore and database cutover. A durable `/app/var/dr/cutover.json` journal
is written before the first destructive database rename. If the worker/container dies mid-cutover,
the next worker instance inspects both the journal and the actual PostgreSQL database names: before
post-cutover verification it conservatively rolls back to B; after verification it completes the
cutover. A rolled-back interrupted operation is marked failed rather than retried destructively in
a loop. Completed recovery also invalidates B's filesystem sessions before maintenance is released.

Backup upload limits are route-specific; the normal small API request limit remains in place.
Backup creation currently streams from a consistent exported PostgreSQL snapshot in the requesting
process; moving creation itself to the DR worker is tracked as the remaining long-request cleanup
before v0.6.0 acceptance.

## Acceptance test

`v0.6.0` is not complete until CI contains a real disaster-recovery integration scenario:

1. start installation A with `APP_KEY=A`;
2. create admin/server/configuration and enroll a real native Go agent;
3. submit metrics and create history/secrets worth checking;
4. create a password-protected full backup;
5. start a fresh installation B with `APP_KEY=B`, where `A != B`;
6. create the temporary B administrator;
7. upload/preflight/restore the backup;
8. assert A users/data/history are present and the temporary B administrator/session is gone;
9. assert registered application secrets decrypt successfully through B's current `SecretCipher`;
10. point the same public test endpoint from A to B without changing the agent config/token;
11. assert the existing agent posts a new metric successfully to the restored server identity.

Negative coverage includes wrong password, corrupted backup and maintenance `503` with successful
agent queue retry after restore.
