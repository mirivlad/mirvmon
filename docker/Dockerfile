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
COPY config ./config

RUN composer dump-autoload \
    --no-dev \
    --classmap-authoritative \
    --no-interaction

FROM dunglas/frankenphp:1.12.6-php8.5-trixie@sha256:da270879b95225345b2ee984f717aef5cba7336e1f206ec005074a79235af347

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        supervisor \
        tini \
    && install-php-extensions \
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
COPY --from=vendor /app/vendor /app/vendor
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-mirvmon.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/mirvmon.conf

RUN chmod 0555 /app/docker/entrypoint.sh /app/bin/migrate /app/bin/offline-worker \
    && chown -R app:app /app/var \
    && composer check-platform-reqs --no-dev

ENV APP_ENV=production \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

EXPOSE 8080

USER app

ENTRYPOINT ["/usr/bin/tini", "--", "/app/docker/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/mirvmon.conf"]
