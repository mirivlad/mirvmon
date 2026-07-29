#!/bin/sh

set -eu
umask 077

script_directory="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
project_directory="$(dirname "$script_directory")"
environment_file="$project_directory/.env"

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker Engine is required." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose v2 is required." >&2
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
    echo "OpenSSL is required to generate application secrets." >&2
    exit 1
fi

if [ ! -f "$environment_file" ]; then
    cp "$project_directory/.env.example" "$environment_file"

    database_password="$(openssl rand -hex 32)"
    application_key="$(openssl rand -base64 32 | tr -d '\n')"

    sed -i "s|^DB_PASSWORD=$|DB_PASSWORD=$database_password|" "$environment_file"
    sed -i "s|^APP_KEY=$|APP_KEY=$application_key|" "$environment_file"

    echo "Created $environment_file with mode 600 and random secrets."
fi

chmod 600 "$environment_file"

docker compose \
    --env-file "$environment_file" \
    -f "$script_directory/docker-compose.yml" \
    -f "$script_directory/docker-compose.build.yml" \
    config --quiet

docker compose \
    --env-file "$environment_file" \
    -f "$script_directory/docker-compose.yml" \
    -f "$script_directory/docker-compose.build.yml" \
    up --detach --build --remove-orphans

docker compose \
    --env-file "$environment_file" \
    -f "$script_directory/docker-compose.yml" \
    -f "$script_directory/docker-compose.build.yml" \
    ps
