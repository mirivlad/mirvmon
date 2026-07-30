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

For production, set `MIRVMON_IMAGE` to the same immutable release tag as the
repository reference. Keep the named volumes when redeploying. Do not enable a
published database port.

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
connectivity and is used by the container health check.

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

Контейнер `app` запускает под `supervisord` три процесса от
непривилегированного пользователя: FrankenPHP, проверку offline-переходов и
доставку уведомлений. `NOTIFICATION_POLL_INTERVAL` задаёт паузу worker при
пустой очереди (1–60 секунд), а `NOTIFICATION_BATCH_SIZE` — размер одного claim
(1–100). Telegram/SMTP delivery никогда не выполняется в процессе ingestion.

Database migrations run under an advisory lock whenever the app container
starts. Applied files are checksum-protected in `schema_migrations`.
The database health check waits for the final PID 1 postmaster rather than the
temporary server used by the TimescaleDB initialization and tuning scripts.
