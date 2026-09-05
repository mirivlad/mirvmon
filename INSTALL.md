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
MIRVMON_IMAGE=ghcr.io/mirivlad/mirvmon:0.6.7
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

## Мониторинг сайтов

Сайты добавляются после входа администратора в `/sites`; проверки выполняет
`website-check-worker` внутри единственного `app` контейнера. Задайте endpoint,
assertions, timeout/deadline, TTFB/total thresholds и при необходимости TLS,
self-signed, redirect origins или domain registration. Состояние worker видно в
`/admin/system`; ручная проверка — `bin/website-check-worker --once`.

Мониторинг сайтов использует explicit trusted-admin модель: администратор может
намеренно проверять любой HTTP(S)-адрес, достижимый из контейнера `app`, включая
localhost, private/link-local адреса и внутренние DNS-имена. Это не SSRF-граница
между недоверенными пользователями: создавать и изменять website checks может
только administrator. Worker разрешает только HTTP(S), не использует ambient
proxy, ограничивает redirect/deadline/размер ответа и снимает credentials и
чувствительные headers при cross-origin redirect, если destination заранее не
разрешён администратором. Тела ответов и секреты не сохраняются в history,
диагностике или HTML. Не добавляйте для сайтов третий Compose service и не
настраивайте website probes на native agent.

## Нативные агенты

После добавления сервера откройте его вкладку «Агент» и скачайте
персонализированный installer. Официальные сборки — только x64.

Linux installer поддерживает systemd-хосты Debian 11+, Ubuntu 20.04+,
CentOS/RHEL/Oracle Linux 7+, AlmaLinux/Rocky Linux 8+ и NethServer 7. Он
устанавливает нативный binary, отдельного пользователя `mirvmon-agent`,
конфигурацию и durable queue; Python после перехода не требуется.

Для Windows используется единый неподписанный `MirvMon-Agent-Setup.exe`. Он
содержит modern и legacy x64 builds и сам выбирает подходящий вариант:
Windows 10/11 и Server 2016–2025 используют modern build, Windows 7 SP1/8/8.1
и Server 2008 R2 SP1/2012/2012 R2 — legacy build. Windows Server 2008 без R2 и
32-bit системы не поддерживаются. PowerShell/BAT установщику не нужны.

Для Windows ссылка скачивания содержит только одноразовый download ticket. Он
погашается при первом скачивании EXE и не является credential, который EXE затем
использует для активации. В собранный installer помещается отдельный одноразовый
activation credential со сроком жизни один час; постоянный agent token остаётся
только в HTTPS-ответе активации и никогда не попадает в URL. Поэтому старые
нескачанные Windows installer-ссылки, созданные до обновления на v0.6.7, нужно
сгенерировать заново. Linux также не помещает permanent token в URL.
При обновлении существующей установки прежний config/queue мигрируется до
переключения, а post-commit failure приводит к rollback.

Capable agent начиная с `v0.4.3` может принимать отдельную self-update команду
из UI. На Linux для привилегированной части создаются
`mirvmon-agent-update.path` и `mirvmon-agent-update.service`; основной процесс
остаётся непривилегированным.

После установки на Linux проверьте:

```bash
sudo systemctl status mirvmon-agent --no-pager -l
sudo /opt/mirvmon-agent/mirvmon-agent version
sudo -u mirvmon-agent /opt/mirvmon-agent/mirvmon-agent check \
  --config /etc/mirvmon-agent/config.json --server
```

Полная справка по файлам, командам, `health.json`, proxy и self-update:
[docs/agent.md](docs/agent.md). Пошаговая диагностика проблем доставки:
[docs/troubleshooting.md](docs/troubleshooting.md).

### Runtime-проверка Windows перед rollout

CI собирает обе Go-версии, компилирует NSIS и проверяет native transaction, но
это не заменяет runtime smoke-test на реальных Windows. Перед широким rollout
желательно проверить хотя бы legacy boundary (Windows 7 SP1/Server 2008 R2
SP1), Windows 8.1/Server 2012 R2 и современную Windows 11/Server 2022:

1. clean install и `sc.exe query MirvMonAgent`;
2. получение remote config и метрик;
3. повторный запуск installer;
4. миграцию legacy Python/PowerShell state, если такой узел есть;
5. отсутствие двух одновременно работающих агентов;
6. self-update capable agent из вкладки «Агент».

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
нужны PHP 8.5, Composer dependencies, расширения `pdo_pgsql`, `curl`, `intl`,
`sodium` и исполняемый `makensis` (NSIS 3). Эта схема не является основным
Portainer deployment и должна сохранять те же environment variables и public
root `public/`.
