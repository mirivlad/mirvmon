ARG APP_VERSION=v0.0.0-development

FROM --platform=$BUILDPLATFORM golang:1.26.6-bookworm@sha256:116d58cbd88c1297624acc6e967a060012422bacf9930927e23fb719189c6f36 AS agent-modern

ARG APP_VERSION

WORKDIR /src/agent

COPY agent/go.mod agent/go.sum ./

RUN go mod download

COPY agent ./

RUN set -eux; \
    export CGO_ENABLED=0 GOOS=linux GOARCH=amd64 GOAMD64=v1; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION} -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Artifact=linux-amd64" \
        -o /out/mirvmon-agent-linux-amd64 ./cmd/mirvmon-agent; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION} -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Artifact=linux-amd64" \
        -o /tmp/mirvmon-agent-linux-amd64 ./cmd/mirvmon-agent; \
    cmp /out/mirvmon-agent-linux-amd64 /tmp/mirvmon-agent-linux-amd64; \
    export GOOS=windows GOARCH=amd64 GOAMD64=v1; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION} -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Artifact=windows-amd64" \
        -o /out/mirvmon-agent-windows-amd64.exe ./cmd/mirvmon-agent; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION} -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Artifact=windows-amd64" \
        -o /tmp/mirvmon-agent-windows-amd64.exe ./cmd/mirvmon-agent; \
    cmp /out/mirvmon-agent-windows-amd64.exe /tmp/mirvmon-agent-windows-amd64.exe

# Intentional compatibility pin: Go 1.20 is the last release line supporting Windows 7/8 and Server 2008 R2/2012.
FROM --platform=$BUILDPLATFORM golang:1.20.14-bookworm@sha256:9fa9101141c01e9440216d32eb2b380b3c3079bea07aeab3546020cc91b3662c AS agent-legacy

ARG APP_VERSION

WORKDIR /src/agent

COPY agent/go.mod agent/go.sum ./

RUN go mod download

COPY agent ./

RUN set -eux; \
    export CGO_ENABLED=0 GOOS=windows GOARCH=amd64 GOAMD64=v1; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION} -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Artifact=windows-legacy-amd64" \
        -o /out/mirvmon-agent-windows-legacy-amd64.exe ./cmd/mirvmon-agent; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION} -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Artifact=windows-legacy-amd64" \
        -o /tmp/mirvmon-agent-windows-legacy-amd64.exe ./cmd/mirvmon-agent; \
    cmp /out/mirvmon-agent-windows-legacy-amd64.exe /tmp/mirvmon-agent-windows-legacy-amd64.exe

FROM agent-modern AS agent-manifest

ARG APP_VERSION

COPY --from=agent-legacy /out/mirvmon-agent-windows-legacy-amd64.exe /out/mirvmon-agent-windows-legacy-amd64.exe

RUN set -eu; \
    cd /out; \
    linux_sha256=$(sha256sum mirvmon-agent-linux-amd64 | cut -d ' ' -f 1); \
    windows_sha256=$(sha256sum mirvmon-agent-windows-amd64.exe | cut -d ' ' -f 1); \
    legacy_windows_sha256=$(sha256sum mirvmon-agent-windows-legacy-amd64.exe | cut -d ' ' -f 1); \
    linux_size=$(stat -c %s mirvmon-agent-linux-amd64); \
    windows_size=$(stat -c %s mirvmon-agent-windows-amd64.exe); \
    legacy_windows_size=$(stat -c %s mirvmon-agent-windows-legacy-amd64.exe); \
    printf '%s\n' \
        '{' \
        '  "version": "'"$APP_VERSION"'",' \
        '  "artifacts": {' \
        '    "linux-amd64": {"filename": "mirvmon-agent-linux-amd64", "sha256": "'"$linux_sha256"'", "size": '"$linux_size"', "content_type": "application/octet-stream"},' \
        '    "windows-amd64": {"filename": "mirvmon-agent-windows-amd64.exe", "sha256": "'"$windows_sha256"'", "size": '"$windows_size"', "content_type": "application/octet-stream"},' \
        '    "windows-legacy-amd64": {"filename": "mirvmon-agent-windows-legacy-amd64.exe", "sha256": "'"$legacy_windows_sha256"'", "size": '"$legacy_windows_size"', "content_type": "application/octet-stream"}' \
        '  }' \
        '}' > manifest.json

FROM scratch AS agent-dist

COPY --from=agent-manifest /out /out

FROM --platform=$BUILDPLATFORM composer:2.10.3@sha256:4d045ea9f71d5d111a95e608400da61d187e487adf9eaf2dfe068998a8d4f584 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-req=ext-pdo_pgsql \
    --prefer-dist

COPY src ./src

RUN composer dump-autoload \
    --no-dev \
    --classmap-authoritative \
    --no-interaction

FROM dunglas/frankenphp:1.12.7-php8.5-trixie@sha256:ee14233b7866ae5e9838ec3afe07721837c0a74b534fb30d0a7f7b373dd2fd12

ARG APP_VERSION

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        fonts-dejavu-core \
        nsis \
        postgresql-client \
        supervisor \
        tini \
    && install-php-extensions \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN useradd \
        --uid 10001 \
        --create-home \
        --home-dir /home/app \
        --shell /usr/sbin/nologin \
        app \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /app/var/cache /app/var/log /config/caddy /data/caddy \
    && chown -R app:app /app/var /config /data

WORKDIR /app

COPY . /app
COPY --from=agent-dist /out /app/agent-dist
COPY --from=vendor /app/vendor /app/vendor
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-mirvmon.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/mirvmon.conf

# The generated legacy installer must not depend on the target host's stale
# CA store. Replace the small source-tree bootstrap fallback with the complete
# CA bundle from this freshly built release image.
RUN install -m 0644 /etc/ssl/certs/ca-certificates.crt /app/agent/internal/tlsroots/roots.pem

RUN chmod 0555 \
        /app/docker/entrypoint.sh \
        /app/bin/migrate \
        /app/bin/dr-worker \
        /app/bin/offline-worker \
        /app/bin/notification-worker \
        /app/bin/website-check-worker \
        /app/bin/audit-retention-worker \
    && chown -R app:app /app/var \
    && composer check-platform-reqs --no-dev

ENV APP_ENV=production \
    APP_VERSION=${APP_VERSION} \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

EXPOSE 8080

# Replaces the inherited FrankenPHP check against the Caddy admin API on port
# 2019, which this image disables in its Caddyfile and can never answer.
HEALTHCHECK --interval=15s --timeout=5s --start-period=15s --retries=5 \
    CMD curl --fail --silent --show-error http://127.0.0.1:8080/readyz || exit 1

USER app

ENTRYPOINT ["/usr/bin/tini", "--", "/app/docker/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/mirvmon.conf"]
