# Установка MirvMon

Поддерживаемый production-вариант — Docker Compose/Portainer с двумя
контейнерами. TLS завершает внешний nginx.

## Требования

- Docker Engine с Compose v2 либо Portainer Docker Standalone;
- внешний nginx с настроенным HTTPS-сертификатом;
- не менее 2 GiB RAM для рекомендованной конфигурации БД;
- опубликованный образ MirvMon, указанный в `MIRVMON_IMAGE`.

## Portainer

Создайте Git stack:

1. repository: `https://github.com/mirivlad/mirvmon`;
2. compose path: `docker/docker-compose.yml`;
3. reference: release tag для production;
4. environment variables: значения из `docker/.env.example`.

Git tag `vX.Y.Z` соответствует Docker image tag `X.Y.Z`.
Например, repository reference `v0.3.7` использует
`MIRVMON_IMAGE=ghcr.io/mirivlad/mirvmon:0.3.7`. После первой публикации
сделайте GHCR package публичным либо настройте registry credentials в Portainer.

Сгенерируйте независимые секреты:

```bash
openssl rand -base64 32  # APP_KEY
openssl rand -hex 32     # SETUP_TOKEN
openssl rand -hex 32     # DB_PASSWORD
```

Обязательные переменные:

```dotenv
MIRVMON_IMAGE=ghcr.io/mirivlad/mirvmon:0.3.5
APP_KEY=<base64-encoded-32-byte-key>
SETUP_TOKEN=<random-hex-token>
DB_PASSWORD=<random-database-password>
```

Не публикуйте порт БД и не храните реальные значения в Git.

## Локальная сборка

```bash
git clone https://github.com/mirivlad/mirvmon.git
cd mirvmon
cp .env.example .env
# Заполните APP_KEY, SETUP_TOKEN и DB_PASSWORD.

docker compose --env-file .env \
  -f docker/docker-compose.yml \
  -f docker/docker-compose.build.yml \
  up -d --build
```

`docker/deploy.sh` выполняет тот же запуск и при первом вызове сам создаёт `.env`
с правами `0600` и случайными секретами.

Если registry недоступен напрямую, настройте proxy Docker daemon. Для локальной
сборки overlay также принимает `HTTP_PROXY`, `HTTPS_PROXY` и `NO_PROXY`.

## Внешний nginx

Приложение по умолчанию доступно только на `127.0.0.1:8080`.

```nginx
server {
    listen 443 ssl http2;
    server_name monitoring.example.com;

    # ssl_certificate и ssl_certificate_key задаются вашей инфраструктурой.

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
}
```

Если внешний URL известен, задайте:

```dotenv
PUBLIC_BASE_URL=https://monitoring.example.com
```

При пустом `PUBLIC_BASE_URL` MirvMon использует host и scheme запроса только от
сетей из `TRUSTED_PROXIES`. Не доверяйте произвольным внешним адресам.
Оставьте `SESSION_SECURE=1`, когда пользовательский трафик приходит через
HTTPS; иначе браузерная сессия намеренно не будет работать по открытому HTTP.

## Первый запуск

После успешного старта откройте:

```text
https://monitoring.example.com/setup
```

Введите `SETUP_TOKEN` и создайте администратора с паролем не короче 12
символов. Стандартной учётной записи нет. Создание через `/setup` блокируется,
как только в таблице пользователей появляется первая запись.

## Проверка

```bash
docker compose --env-file .env -f docker/docker-compose.yml ps
curl --fail http://127.0.0.1:8080/livez
curl --fail http://127.0.0.1:8080/readyz
```

- `/livez` проверяет HTTP runtime;
- `/readyz` проверяет приложение и соединение с TimescaleDB;
- healthcheck контейнера использует `/readyz`.

## Нативные агенты

Поддерживаются только x64-системы. Linux-агент Go 1.26.5 работает на systemd
хостах Debian 11+, Ubuntu 20.04+, CentOS/RHEL/Oracle Linux 7+,
AlmaLinux/Rocky Linux 8+ и NethServer 7. Он не требует Python, пакетного
менеджера или сторонних репозиториев: нужен только `curl` либо `wget`.

Windows Server 2012 R2+ и Windows 10+ используют Go 1.26.5. Windows 7 SP1 x64
и Windows Server 2008 R2 x64 используют отдельную сборку Go 1.20.14 и
совместимый с PowerShell 2.0 installer. Windows Server 2008 без R2 и 32-bit
платформы не поддерживаются.

Все установщики создают native service, сохраняют configuration и bounded queue
в защищённых каталогах и перед переключением импортируют state старого
Python/PowerShell агента в отдельные файлы. При неудаче исходные state files
не изменяются. После успешного переключения Linux-установщик удаляет только
известные runtime-файлы старого Python-агента.

### Первый переход и последующие обновления агента

Агенты до `v0.4.3` не умеют принимать команду обновления. После обновления
серверного контейнера до `v0.4.3` один раз скачайте новый установщик во вкладке
«Агент» каждого сервера и запустите его вручную. Установленный `v0.4.3` сможет
самостоятельно переходить на следующие версии по отдельной команде
администратора в этой вкладке.

Linux installer дополнительно создаёт и включает:

- `/etc/systemd/system/mirvmon-agent-update.path`;
- `/etc/systemd/system/mirvmon-agent-update.service`.

Основной service продолжает работать от `mirvmon-agent`; updater запускается от
root только при появлении фиксированного
`/var/lib/mirvmon-agent/update-request.json`. Windows использует уже защищённый
LocalSystem service и helper в `%ProgramData%\MirvMon\Agent`. В update-команде
нет URL, пути или credential; скачивание выполняется с origin мониторинга с
обязательной проверкой TLS, размера, SHA-256 и identity бинарника. Неуспешный
запуск автоматически возвращает `.previous`.

## Обновление

1. Сделайте backup БД.
2. Укажите новый immutable tag в `MIRVMON_IMAGE`.
3. Выполните redeploy stack.
4. Проверьте `/readyz` и журналы.

Шаг 2 обязателен. Тег `latest` изменяемый: если образ с таким именем уже есть
на хосте, `docker compose up -d` переиспользует его и стек молча
переразвернётся на прежнем релизе. Сдвинуть `latest` можно только явным
`docker compose pull` или опцией Portainer «Re-pull image and redeploy», а
новый номерной тег вызывает pull сам. Что лежит на хосте, показывает
`docker image inspect <образ> --format '{{index .RepoDigests 0}}'`.

Миграции выполняются автоматически при старте под advisory lock. Приложение
откажется запускаться, если checksum уже применённого SQL-файла изменился.

## Backup

```bash
docker compose --env-file .env -f docker/docker-compose.yml exec -T db \
  pg_dump --format=custom --no-owner \
  --username=mirvmon mirvmon > mirvmon.dump
```

Имя пользователя и БД замените, если меняли `DB_USERNAME` или `DB_NAME`.

## Альтернативный HTTP runtime

Прикладное ядро не зависит от FrankenPHP. Для запуска через nginx + PHP-FPM
нужны PHP 8.5, Composer dependencies и расширения `pdo_pgsql`, `curl`, `intl`,
`sodium`. Эта схема не является основным Portainer deployment и должна
сохранять те же environment variables и public root `public/`.
