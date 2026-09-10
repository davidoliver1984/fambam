#!/bin/sh

set -eu

repository_root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "${repository_root}"

health_json="$(docker compose exec -T api php artisan fambam:backup-health 2>/dev/null || true)"
database_artifact="$(printf '%s' "${health_json}" | jq -r '.database.reference // empty')"
database_sha256="$(printf '%s' "${health_json}" | jq -r '.database.sha256 // empty')"
object_storage_artifact="$(printf '%s' "${health_json}" | jq -r '.object_storage.reference // empty')"
if [ -z "${database_artifact}" ] || [ -z "${database_sha256}" ] || [ -z "${object_storage_artifact}" ]; then
    echo "No complete S04 backup evidence is available for a restore drill." >&2
    exit 1
fi
case "${database_artifact}" in
    .local/backups/database/*.dump) ;;
    *) echo "Database backup reference is outside the private backup directory." >&2; exit 1 ;;
esac
case "${object_storage_artifact}" in
    .local/backups/object-storage/*.json) ;;
    *) echo "Object-storage reference is outside the private backup directory." >&2; exit 1 ;;
esac
if [ ! -f "${database_artifact}" ] || [ ! -f "${object_storage_artifact}" ]; then
    echo "A named S04 backup artifact is missing." >&2
    exit 1
fi

actual_sha256="$(openssl dgst -sha256 "${database_artifact}" | awk '{print $NF}')"
if [ "${actual_sha256}" != "${database_sha256}" ]; then
    echo "Database backup checksum does not match its recorded evidence." >&2
    exit 1
fi
if [ "$(jq -r '.versioning.Status // empty' "${object_storage_artifact}")" != "Enabled" ]; then
    echo "Object-storage evidence does not prove versioning was enabled." >&2
    exit 1
fi

captured_at="$(jq -r '.captured_at // empty' "${object_storage_artifact}")"
snapshot_at="$(printf '%s' "${captured_at}" | sed -E 's/^([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})Z$/\1-\2-\3T\4:\5:\6Z/')"
if [ "${snapshot_at}" = "${captured_at}" ]; then
    echo "Object-storage evidence has an invalid capture timestamp." >&2
    exit 1
fi

restore_directory=".local/backups/restore"
report="${restore_directory}/fambam-${captured_at}.json"
temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/fambam-restore.XXXXXX")"
container_name="fambam-restore-drill-$$"
database_name="fambam_restore_drill"
database_user="fambam"
database_password="fambam"
runtime_user="fambam_app"
runtime_password="fambam_app"
bucket="$(jq -r '.bucket // empty' "${object_storage_artifact}")"
mkdir -p "${restore_directory}"

cleanup() {
    status=$?
    docker rm --force "${container_name}" >/dev/null 2>&1 || true
    docker compose exec -T localstack rm -f /tmp/fambam-restore-drill-object >/dev/null 2>&1 || true
    rm -rf "${temporary_directory}"
    if [ "${status}" -ne 0 ]; then
        jq -n \
            --arg result failed \
            --arg snapshot_at "${snapshot_at}" \
            --arg database_artifact "${database_artifact}" \
            --arg object_storage_artifact "${object_storage_artifact}" \
            '{schema_version: 1, result: $result, snapshot_at: $snapshot_at, database_artifact: $database_artifact, object_storage_artifact: $object_storage_artifact}' \
            >"${report}"
        docker compose exec -T api php artisan fambam:backup-health:record \
            --restore-result=failed --restore-reference="${report}" >/dev/null 2>&1 || true
    fi
    exit "${status}"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

docker run --detach --name "${container_name}" \
    --env POSTGRES_DB="${database_name}" \
    --env POSTGRES_USER="${database_user}" \
    --env POSTGRES_PASSWORD="${database_password}" \
    --publish 127.0.0.1::5432 \
    pgvector/pgvector:pg17 >/dev/null
ready_checks=0
while [ "${ready_checks}" -lt 3 ]; do
    if docker exec "${container_name}" pg_isready --username "${database_user}" --dbname "${database_name}" >/dev/null 2>&1; then
        ready_checks=$((ready_checks + 1))
    else
        ready_checks=0
    fi
    sleep 1
done
host_port="$(docker port "${container_name}" 5432/tcp | sed 's/.*://')"

docker run --rm --network "container:${container_name}" \
    --env PGHOST=127.0.0.1 \
    --env PGDATABASE="${database_name}" \
    --env PGUSER="${database_user}" \
    --env PGPASSWORD="${database_password}" \
    --env DB_RUNTIME_USERNAME="${runtime_user}" \
    --env DB_RUNTIME_PASSWORD="${runtime_password}" \
    --volume "${repository_root}/infrastructure/docker/postgres/provision-runtime-role.sh:/opt/fambam/provision-runtime-role.sh:ro" \
    pgvector/pgvector:pg17 /opt/fambam/provision-runtime-role.sh >/dev/null

docker exec -i "${container_name}" pg_restore \
    --username "${database_user}" \
    --dbname "${database_name}" \
    --no-owner \
    --no-acl <"${database_artifact}"

reconcile_output="$(
    cd apps/api
    APP_ENV=local \
    FAMBAM_RESTORE_DRILL_ENABLED=true \
    DB_CONNECTION=pgsql \
    DB_HOST=127.0.0.1 \
    DB_PORT="${host_port}" \
    DB_DATABASE="${database_name}" \
    DB_USERNAME="${runtime_user}" \
    DB_PASSWORD="${runtime_password}" \
    DB_RUNTIME_USERNAME="${runtime_user}" \
    FILESYSTEM_DISK=s3 \
    AWS_ACCESS_KEY_ID=test \
    AWS_SECRET_ACCESS_KEY=test \
    AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-eu-west-2}" \
    AWS_BUCKET="${bucket}" \
    AWS_ENDPOINT="http://127.0.0.1:${LOCALSTACK_PORT:-4570}" \
    AWS_USE_PATH_STYLE_ENDPOINT=true \
        php artisan fambam:restore:reconcile-deletions --snapshot-at="${snapshot_at}"
)"
reconciled_deletions="$(printf '%s' "${reconcile_output}" | sed -n 's/.*Reconciled \([0-9][0-9]*\) post-snapshot.*/\1/p')"
if [ -z "${reconciled_deletions}" ]; then
    echo "Restore deletion reconciliation did not return a count." >&2
    exit 1
fi

invalid_originals="$(docker exec "${container_name}" psql --username "${database_user}" --dbname "${database_name}" --tuples-only --no-align --command "SELECT count(*) FROM media_uploads WHERE state IN ('preserved','processing','ready','degraded') AND (original_object_key IS NULL OR original_sha256 IS NULL);")"
if [ "${invalid_originals}" -ne 0 ]; then
    echo "Restored database contains preserved uploads without complete original identity." >&2
    exit 1
fi

broken_photo_people="$(docker exec "${container_name}" psql --username "${database_user}" --dbname "${database_name}" --tuples-only --no-align --command "SELECT count(*) FROM photo_people pp LEFT JOIN photos p ON p.id = pp.photo_id AND p.family_space_id = pp.family_space_id LEFT JOIN people pe ON pe.id = pp.person_id AND pe.family_space_id = pp.family_space_id WHERE pp.status = 'approved' AND (p.id IS NULL OR pe.id IS NULL);")"
if [ "${broken_photo_people}" -ne 0 ]; then
    echo "Restored database contains an approved PhotoPerson with a missing Photo or Person." >&2
    exit 1
fi

docker exec "${container_name}" psql --username "${database_user}" --dbname "${database_name}" \
    --tuples-only --no-align --field-separator '|' \
    --command "SELECT original_object_key, original_sha256 FROM media_uploads WHERE state IN ('preserved','processing','ready','degraded') ORDER BY id;" \
    >"${temporary_directory}/originals.txt"
verified_originals=0
expected_originals="$(awk 'NF { count++ } END { print count + 0 }' "${temporary_directory}/originals.txt")"
while IFS='|' read -r object_key expected_sha256; do
    if [ -z "${object_key}" ]; then
        continue
    fi
    version_id="$(jq -r --arg key "${object_key}" '[.inventory.Versions[]? | select(.Key == $key)] | sort_by(.LastModified) | last | .VersionId // empty' "${object_storage_artifact}")"
    if [ -z "${version_id}" ]; then
        echo "Object-version evidence is missing a preserved original." >&2
        exit 1
    fi
    docker compose exec -T localstack awslocal s3api get-object \
        --bucket "${bucket}" --key "${object_key}" --version-id "${version_id}" \
        /tmp/fambam-restore-drill-object </dev/null >/dev/null
    docker compose cp localstack:/tmp/fambam-restore-drill-object "${temporary_directory}/original" >/dev/null 2>&1
    restored_sha256="$(openssl dgst -sha256 "${temporary_directory}/original" | awk '{print $NF}')"
    if [ "${restored_sha256}" != "${expected_sha256}" ]; then
        echo "A restored preserved original failed checksum verification." >&2
        exit 1
    fi
    verified_originals=$((verified_originals + 1))
done <"${temporary_directory}/originals.txt"
if [ "${verified_originals}" -ne "${expected_originals}" ]; then
    echo "Restore verification did not process every preserved original." >&2
    exit 1
fi

jq -n \
    --arg result passed \
    --arg snapshot_at "${snapshot_at}" \
    --arg database_artifact "${database_artifact}" \
    --arg database_sha256 "${database_sha256}" \
    --arg object_storage_artifact "${object_storage_artifact}" \
    --argjson reconciled_deletions "${reconciled_deletions}" \
    --argjson verified_originals "${verified_originals}" \
    '{schema_version: 1, result: $result, snapshot_at: $snapshot_at, database_artifact: $database_artifact, database_sha256: $database_sha256, object_storage_artifact: $object_storage_artifact, reconciled_deletions: $reconciled_deletions, verified_originals: $verified_originals, broken_approved_photo_people: 0}' \
    >"${report}"
docker compose exec -T api php artisan fambam:backup-health:record \
    --restore-result=passed --restore-reference="${report}"

printf 'Restore drill passed: %s\n' "${report}"
printf 'Verified preserved originals: %s\n' "${verified_originals}"
printf 'Reconciled post-snapshot deletions: %s\n' "${reconciled_deletions}"
