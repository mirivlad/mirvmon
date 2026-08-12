ARG APP_VERSION=development

FROM golang:1.26.5-bookworm@sha256:53eeac89074db483fdf0ab3be1df32bf6e47562263d2d0d6baa7f26acb4957dd AS agent-modern

ARG APP_VERSION

WORKDIR /src/agent

COPY agent/go.mod agent/go.sum ./

RUN go mod download

COPY agent ./

RUN set -eux; \
    export CGO_ENABLED=0 GOOS=linux GOARCH=amd64 GOAMD64=v1; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION}" \
        -o /out/mirvmon-agent-linux-amd64 ./cmd/mirvmon-agent; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION}" \
        -o /tmp/mirvmon-agent-linux-amd64 ./cmd/mirvmon-agent; \
    cmp /out/mirvmon-agent-linux-amd64 /tmp/mirvmon-agent-linux-amd64; \
    export GOOS=windows GOARCH=amd64 GOAMD64=v1; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION}" \
        -o /out/mirvmon-agent-windows-amd64.exe ./cmd/mirvmon-agent; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION}" \
        -o /tmp/mirvmon-agent-windows-amd64.exe ./cmd/mirvmon-agent; \
    cmp /out/mirvmon-agent-windows-amd64.exe /tmp/mirvmon-agent-windows-amd64.exe

FROM golang:1.20.14-bookworm@sha256:9fa9101141c01e9440216d32eb2b380b3c3079bea07aeab3546020cc91b3662c AS agent-legacy

ARG APP_VERSION

WORKDIR /src/agent

COPY agent/go.mod agent/go.sum ./

RUN go mod download

COPY agent ./

RUN set -eux; \
    export CGO_ENABLED=0 GOOS=windows GOARCH=amd64 GOAMD64=v1; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION}" \
        -o /out/mirvmon-agent-windows-legacy-amd64.exe ./cmd/mirvmon-agent; \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/mirivlad/mirvmon/agent/internal/buildinfo.Version=${APP_VERSION}" \
        -o /tmp/mirvmon-agent-windows-legacy-amd64.exe ./cmd/mirvmon-agent; \
    cmp /out/mirvmon-agent-windows-legacy-amd64.exe /tmp/mirvmon-agent-windows-legacy-amd64.exe

FROM agent-modern AS agent-manifest

COPY --from=agent-legacy /out/mirvmon-agent-windows-legacy-amd64.exe /out/mirvmon-agent-windows-legacy-amd64.exe

RUN set -eu; \
    cd /out; \
    linux_sha256=$(sha256sum mirvmon-agent-linux-amd64 | cut -d ' ' -f 1); \
    windows_sha256=$(sha256sum mirvmon-agent-windows-amd64.exe | cut -d ' ' -f 1); \
    legacy_windows_sha256=$(sha256sum mirvmon-agent-windows-legacy-amd64.exe | cut -d ' ' -f 1); \
    printf '%s\n' \
        '{' \
        '  "artifacts": {' \
        '    "linux-amd64": {"filename": "mirvmon-agent-linux-amd64", "sha256": "'"$linux_sha256"'", "content_type": "application/octet-stream"},' \
        '    "windows-amd64": {"filename": "mirvmon-agent-windows-amd64.exe", "sha256": "'"$windows_sha256"'", "content_type": "application/octet-stream"},' \
        '    "windows-legacy-amd64": {"filename": "mirvmon-agent-windows-legacy-amd64.exe", "sha256": "'"$legacy_windows_sha256"'", "content_type": "application/octet-stream"}' \
        '  }' \
        '}' > manifest.json

FROM scratch AS agent-dist

COPY --from=agent-manifest /out /out

FROM composer:2.10.1@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS vendor

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

FROM dunglas/frankenphp:1.12.6-php8.5-trixie@sha256:da270879b95225345b2ee984f717aef5cba7336e1f206ec005074a79235af347

ARG APP_VERSION

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        fonts-dejavu-core \
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

RUN chmod 0555 \
        /app/docker/entrypoint.sh \
        /app/bin/migrate \
        /app/bin/offline-worker \
        /app/bin/notification-worker \
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
