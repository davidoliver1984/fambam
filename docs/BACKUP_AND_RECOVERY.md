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

Phase 13 stage 5 consumes the named artifacts produced here for an isolated,
scripted restore exercise and records its result through the same evidence
contract. Until that first successful drill, an otherwise current backup is
correctly reported as degraded.

## Production binding requirements

The production infrastructure provider remains deliberately unselected. Any
future binding must provide automated whole-database snapshots,
point-in-time recovery, declared retention, and encryption at rest. Media
backup must retain recoverable object versions and provide an inspectable,
timestamped verification signal. Credentials, encryption keys, and private
object content must never be stored in backup-health evidence.
