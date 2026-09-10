#!/bin/sh

set -eu

repository_root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "${repository_root}"

bucket="${AWS_BUCKET:-fambam-media}"
database="${POSTGRES_DB:-fambam}"
database_user="${POSTGRES_USER:-fambam}"
captured_at="$(date -u +%Y%m%dT%H%M%SZ)"
database_directory=".local/backups/database"
object_storage_directory=".local/backups/object-storage"
database_artifact="${database_directory}/fambam-${captured_at}.dump"
object_storage_artifact="${object_storage_directory}/fambam-${captured_at}.json"

mkdir -p "${database_directory}" "${object_storage_directory}"
temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/fambam-backup.XXXXXX")"
trap 'rm -rf "${temporary_directory}"' EXIT HUP INT TERM

docker compose exec -T postgres pg_dump \
    --username "${database_user}" \
    --dbname "${database}" \
    --format custom \
    --no-owner >"${temporary_directory}/database.dump"

docker compose exec -T localstack awslocal s3api get-bucket-versioning \
    --bucket "${bucket}" \
    --output json >"${temporary_directory}/versioning.json"
if [ "$(jq -r '.Status // empty' "${temporary_directory}/versioning.json")" != "Enabled" ]; then
    echo "Refusing to record backup evidence because bucket versioning is not enabled." >&2
    exit 1
fi

docker compose exec -T localstack awslocal s3api list-object-versions \
    --bucket "${bucket}" \
    --output json >"${temporary_directory}/versions.json"

jq -n \
    --arg captured_at "${captured_at}" \
    --arg bucket "${bucket}" \
    --slurpfile versioning "${temporary_directory}/versioning.json" \
    --slurpfile inventory "${temporary_directory}/versions.json" \
    '{captured_at: $captured_at, bucket: $bucket, versioning: $versioning[0], inventory: $inventory[0]}' \
    >"${temporary_directory}/object-storage.json"

mv "${temporary_directory}/database.dump" "${database_artifact}"
mv "${temporary_directory}/object-storage.json" "${object_storage_artifact}"
database_sha256="$(openssl dgst -sha256 "${database_artifact}" | awk '{print $NF}')"

docker compose exec -T api php artisan fambam:backup-health:record \
    --database-reference="${database_artifact}" \
    --database-sha256="${database_sha256}" \
    --object-storage-reference="${object_storage_artifact}"

printf 'Database backup: %s\n' "${database_artifact}"
printf 'Database SHA-256: %s\n' "${database_sha256}"
printf 'Object-storage evidence: %s\n' "${object_storage_artifact}"
