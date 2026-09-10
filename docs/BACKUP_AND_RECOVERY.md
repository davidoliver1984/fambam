# Backup and recovery

Fambam treats the whole PostgreSQL database and preserved media originals as
authoritative. Ordinary database backups are whole-database backups; they do
not selectively omit tables merely because some rows can be regenerated.

This specifically keeps notification preferences, approved face-identity
assignments, decided face-identity suppressions, and every face observation
referenced by either identity record. Unreferenced recognition runs, search
projections, homepage activities, notification candidates, notifications, and
delivery rows are rebuildable or expendable, but that classification does not
turn the ordinary PostgreSQL backup into a selective table export. The whole
database is still captured.

## Local backup evidence

The local development stack enables versioning on its private media bucket.
With the stack running and migrations current, create real backup inputs with:

```sh
scripts/create-local-backup.sh
```

The command creates a PostgreSQL custom-format dump under
`.local/backups/database/` and a timestamped object-version inventory under
`.local/backups/object-storage/`. Both paths are private, ignored development
artifacts. It records the dump checksum and both artifact references in the
`backup_health_evidence` singleton row only after both artifacts exist and
bucket versioning has been verified as enabled.

Inspect the provider-neutral health contract with:

```sh
docker compose exec api php artisan fambam:backup-health
```

The command exits unsuccessfully when the database backup, object-storage
verification, or restore drill is missing, stale, or failed. Age thresholds
are configurable through `BACKUP_DATABASE_MAX_AGE_HOURS` (26 hours),
`BACKUP_OBJECT_STORAGE_MAX_AGE_HOURS` (26 hours), and
`BACKUP_RESTORE_DRILL_MAX_AGE_DAYS` (31 days). These monitoring thresholds are
not production recovery-point or recovery-time commitments.

Run the Phase 13 restore exercise with:

```sh
scripts/run-local-restore-drill.sh
```

The script refuses unnamed or out-of-scope inputs. It reads the exact artifacts
from recorded evidence, verifies the dump checksum, restores PostgreSQL into a
disposable PostgreSQL 17 container, provisions the restricted application role,
restores its database privileges, and performs deletion reconciliation through
that role. It restores every preserved original into temporary isolated storage
from its captured object version. It verifies every original checksum and every
approved Photo-to-Person relationship before recording a timestamped pass/fail
report under `.local/backups/restore/`.

Completed Family Space deletions are recorded separately from PostgreSQL under
the versioned `platform/deletion-ledger/` object prefix. Records contain only
the Family Space ULID, actor user id, completion timestamp and schema version;
they are not removed with a family's media prefix. During a drill, deletions
newer than the backup timestamp are idempotently reapplied to the isolated
database before consistency checks, preventing a restore from resurrecting a
deliberately deleted Family Space.

The same procedure covers the accepted disaster families without pretending a
provider has been selected: total PostgreSQL loss restores the whole dump;
accidental object deletion reads the captured version rather than the current
key; checksum and relational checks detect single-family or application-caused
corruption; a failed migration restores the preceding named dump; compromised
credentials are rotated before restored services are admitted; temporary export
artifacts are regenerated from authoritative data; and a regional recovery uses
the same provider-neutral inputs in a replacement environment.

## Production binding requirements

The production infrastructure provider remains deliberately unselected. Any
future binding must provide automated whole-database snapshots,
point-in-time recovery, declared retention, and encryption at rest. Media
backup must retain recoverable object versions and provide an inspectable,
timestamped verification signal. Credentials, encryption keys, and private
object content must never be stored in backup-health evidence.
