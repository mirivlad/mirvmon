# MirvMon in Docker

Production uses two containers:

- `app`: rootless FrankenPHP/PHP 8.5 application on container port 8080;
- `db`: PostgreSQL 17 with TimescaleDB 2.28, available only on the internal
  Compose network.

TLS is terminated by an external nginx reverse proxy. The application port is
bound to `127.0.0.1:8080` by default and the database is never published.

## Portainer

Create a **Docker Standalone** stack from a Git repository:

1. Repository: `https://github.com/mirivlad/mirvmon`
2. Reference: a release tag for production, or `master` while developing.
3. Compose path: `docker/docker-compose.yml`
4. Add the variables from `docker/.env.example`.

`APP_KEY`, `SETUP_TOKEN`, and `DB_PASSWORD` are required and must be random. Generate them
outside Portainer:

```bash
openssl rand -base64 32
openssl rand -hex 32
openssl rand -hex 32
```

After the first start, open `/setup`, enter `SETUP_TOKEN`, and create the first
administrator. MirvMon never seeds a default account or password. Once any user
exists, `/setup` no longer permits account creation.

For production, Git reference `vX.Y.Z` maps to Docker image tag `X.Y.Z`.
For example, `v0.1.0` requires `MIRVMON_IMAGE=ghcr.io/mirivlad/mirvmon:0.1.0`.
Keep the named volumes when redeploying. Do not enable a published database
port.

Pin that version tag instead of `latest`. `latest` is mutable: when the host
already holds an image under that name, `docker compose up -d` reuses it and
the stack silently redeploys the previous release. Moving `latest` forward
requires `docker compose pull` or the Portainer **Re-pull image and redeploy**
option, while a new version tag always forces the pull by itself. Verify what
the host actually holds with
`docker image inspect <image> --format '{{index .RepoDigests 0}}'`.

Pushing a `vX.Y.Z` Git tag publishes `linux/amd64` and `linux/arm64` images to
GHCR with semver tags, SBOM, and provenance. Prerelease tags do not move
`latest`. Make the GHCR package public after its first publication, or
configure registry credentials in Portainer.

The production Compose file pulls a published image and contains no `build`
step. This works with local and remote Portainer Docker environments.

## Local build

The optional build overlay compiles the image from the checked-out source:

```bash
cp .env.example .env
# Set APP_KEY, SETUP_TOKEN, and DB_PASSWORD.
docker compose --env-file .env \
  -f docker/docker-compose.yml \
  -f docker/docker-compose.build.yml \
  up -d --build
```

Alternatively, `docker/deploy.sh` creates `.env` with random secrets, validates
the Compose model, builds and starts the same two services.

## External nginx

Example location inside an existing HTTPS server:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 60s;
    client_max_body_size 2m;
}
```

Agents make outbound `POST` requests to the public HTTPS URL. They do not need
an inbound port and therefore work behind NAT.

Set `PUBLIC_BASE_URL=https://monitoring.example.com` when the canonical URL is
known. If it is empty, MirvMon derives installer URLs from trusted reverse-proxy
headers. Never expose port 8080 to an untrusted network while accepting proxy
headers. Keep `SESSION_SECURE=1` for the HTTPS deployment; use `0` only for
isolated direct-HTTP development.

Installer links are one-time credentials valid for one hour. Downloading an
installer exchanges its credential for an agent token; the permanent token is
embedded only in the downloaded script, never in a download URL, and only its
SHA-256 hash is stored by MirvMon.

## Operations

Health:

```bash
docker compose -f docker/docker-compose.yml ps
curl --fail http://127.0.0.1:8080/livez
curl --fail http://127.0.0.1:8080/readyz
```

`/livez` checks the HTTP runtime; `/readyz` additionally checks database
connectivity and is used by the container health check, both in the image and
in the Compose service. A container reported unhealthy with
`Failed to connect to localhost port 2019` runs a MirvMon image older than
0.1.3 under a Compose file without the `healthcheck:` block: that probe belongs
to the FrankenPHP base image and targets the Caddy admin API, which the
Caddyfile disables. Confirm which probe a container uses with
`docker inspect <container> --format '{{json .Config.Healthcheck}}'`.

A Telegram proxy running on the Docker host itself is reachable from the app
container as `host.docker.internal`; the Compose service publishes that name
through `host-gateway`. Inside the container `127.0.0.1` is the container.

Logs:

```bash
docker compose -f docker/docker-compose.yml logs -f app
docker compose -f docker/docker-compose.yml logs -f db
```

Database backup:

```bash
docker compose -f docker/docker-compose.yml exec -T db \
  pg_dump --format=custom --no-owner --username="$DB_USERNAME" "$DB_NAME" \
  > mirvmon.dump
```

Контейнер `app` запускает под `supervisord` семь процессов от
непривилегированного пользователя: FrankenPHP, `connectivity-worker`,
offline-worker, `website-check-worker`, notification-worker,
audit-retention-worker и отдельный `dr-worker` для disaster recovery. `DR_WORKER_INTERVAL` задаёт паузу DR worker
(1–30 секунд), а `BACKUP_MAX_UPLOAD_BYTES` — максимальный размер загружаемого
full backup (по умолчанию 8 GiB). `WEBSITE_CHECK_LOOP_INTERVAL` задаёт паузу
website worker (1–60 секунд), `NOTIFICATION_POLL_INTERVAL` — паузу notification
worker при пустой очереди (1–60 секунд), а `NOTIFICATION_BATCH_SIZE` — размер
одного claim (1–100). Параметры независимой проверки внешней связности
редактируются в **System / MirvMon**: список `host:port`, quorum, период и timeout.
`CONNECTIVITY_PROBE_TARGETS`, `CONNECTIVITY_PROBE_QUORUM`,
`CONNECTIVITY_PROBE_TIMEOUT` и `CONNECTIVITY_CHECK_INTERVAL` остаются bootstrap
defaults для новой/ещё не настроенной установки. По умолчанию используются
`one.one.one.one:443`, `dns.google:443` и `dns.quad9.net:443`, quorum 2 из 3,
период 15 секунд и timeout 1 second. Изменения из UI подхватываются worker без
redeploy контейнера. При потере quorum централизованные website checks приостанавливаются; server-offline
считается недостоверным только если одновременно массово протухают ранее
наблюдаемые агенты.
Telegram/SMTP delivery никогда не выполняется в процессе ingestion.

Website worker получает задания из БД, выполняет HTTP/TLS/RDAP/WHOIS проверки
вне длинной транзакции, продлевает lease и пишет heartbeat. `maintenance`
подавляет только delivery, а `pause` сайта останавливает его проверки. Сырые
samples хранятся 30 days ориентировочно; hourly/daily aggregates позволяют
строить историю до 365 days при соответствующей retention policy.

Database migrations run under an advisory lock whenever the app container
starts. Applied files are checksum-protected in `schema_migrations`.
The database health check waits for the final PID 1 postmaster rather than the
temporary server used by the TimescaleDB initialization and tuning scripts.
