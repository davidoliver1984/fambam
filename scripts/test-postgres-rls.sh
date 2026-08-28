#!/bin/sh

set -eu

container_name="fambam-p03s04-rls-$$"
database_name="fambam_rls"
owner_name="fambam_owner"
owner_password="fambam_owner"
runtime_name="fambam_app"
runtime_password="fambam_app"

cleanup() {
    docker rm --force "$container_name" >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

docker run --detach --name "$container_name" \
    --env POSTGRES_DB="$database_name" \
    --env POSTGRES_USER="$owner_name" \
    --env POSTGRES_PASSWORD="$owner_password" \
    --publish 127.0.0.1::5432 \
    pgvector/pgvector:pg17 >/dev/null

until docker exec "$container_name" pg_isready --username "$owner_name" --dbname "$database_name" >/dev/null 2>&1; do
    sleep 1
done

host_port="$(docker port "$container_name" 5432/tcp | sed 's/.*://')"

docker run --rm --network "container:$container_name" \
    --env PGHOST=127.0.0.1 \
    --env PGDATABASE="$database_name" \
    --env PGUSER="$owner_name" \
    --env PGPASSWORD="$owner_password" \
    --env DB_RUNTIME_USERNAME="$runtime_name" \
    --env DB_RUNTIME_PASSWORD="$runtime_password" \
    --volume "$(pwd)/infrastructure/docker/postgres/provision-runtime-role.sh:/opt/fambam/provision-runtime-role.sh:ro" \
    pgvector/pgvector:pg17 /opt/fambam/provision-runtime-role.sh >/dev/null

(
    cd apps/api
    DB_CONNECTION=pgsql \
    DB_HOST=127.0.0.1 \
    DB_PORT="$host_port" \
    DB_DATABASE="$database_name" \
    DB_USERNAME="$owner_name" \
    DB_PASSWORD="$owner_password" \
    DB_RUNTIME_USERNAME="$runtime_name" \
    php artisan migrate:fresh --force

    DB_CONNECTION=pgsql \
    DB_HOST=127.0.0.1 \
    DB_PORT="$host_port" \
    DB_DATABASE="$database_name" \
    DB_USERNAME="$runtime_name" \
    DB_PASSWORD="$runtime_password" \
    DB_RUNTIME_USERNAME="$runtime_name" \
    DB_ADMIN_HOST=127.0.0.1 \
    DB_ADMIN_PORT="$host_port" \
    DB_ADMIN_DATABASE="$database_name" \
    DB_ADMIN_USERNAME="$owner_name" \
    DB_ADMIN_PASSWORD="$owner_password" \
    php artisan test tests/Feature/PostgresRowLevelSecurityTest.php tests/Feature/FaceEmbeddingProjectionPostgresTest.php
)
