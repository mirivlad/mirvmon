#!/bin/sh
set -eu

image="${MIRVMON_IMAGE:-mirvmon:test}"
db_image="timescale/timescaledb:2.28.3-pg17@sha256:9372c578c11ea83c56e4a8f7af6ec4156646722d270f5ade79559ccb101161a9"
network="mirvmon-dr-acceptance-$$"
database_container="mirvmon-dr-db-$$"
password="integration-only-dr-password"

cleanup() {
    docker rm -f "$database_container" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

docker network create "$network" >/dev/null
docker run -d --name "$database_container" --network "$network" --network-alias dr-db \
    -e POSTGRES_USER=mirvmon \
    -e POSTGRES_PASSWORD="$password" \
    -e POSTGRES_DB=postgres \
    -e TIMESCALEDB_TELEMETRY=off \
    "$db_image" >/dev/null

attempt=0
until docker exec "$database_container" sh -c 'head -1 /var/lib/postgresql/data/postmaster.pid | grep -qx 1 && pg_isready -U mirvmon -d postgres' >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        docker logs "$database_container" >&2 || true
        echo "TimescaleDB did not become ready for DR acceptance." >&2
        exit 1
    fi
    sleep 1
done

docker run --rm --network "$network" --entrypoint php \
    -v "$(pwd)/tests/Acceptance:/acceptance:ro" \
    -e DR_ACCEPTANCE_DB_HOST=dr-db \
    -e DR_ACCEPTANCE_DB_PORT=5432 \
    -e DR_ACCEPTANCE_DB_USERNAME=mirvmon \
    -e DR_ACCEPTANCE_DB_PASSWORD="$password" \
    -e DR_ACCEPTANCE_AGENT=/app/agent-dist/mirvmon-agent-linux-amd64 \
    -e DR_ACCEPTANCE_APP_ROOT=/app \
    "$image" /acceptance/backup-dr.php
