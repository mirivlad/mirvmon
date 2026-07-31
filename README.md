# MirvMon

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

MirvMon — self-hosted система мониторинга серверов. Агенты сами отправляют
метрики исходящими HTTPS-запросами, поэтому для наблюдаемых серверов не нужны
входящие порты и белые IP-адреса.

## Текущий стек

- PHP 8.5, Slim 4 и Twig 3;
- FrankenPHP в classic mode как основной HTTP runtime;
- PostgreSQL 17 с TimescaleDB 2.28 для метрик и агрегатов;
- Python-агент с `psutil`;
- локальные Bootstrap 5.3.8, Chart.js 4.5.1 и Font Awesome 7.3.1.

Прикладной код использует PSR-интерфейсы и не обращается к API FrankenPHP или
Caddy. При необходимости HTTP-слой можно запустить через nginx + PHP-FPM без
изменения контроллеров и бизнес-логики.

## Архитектура развёртывания

Production stack состоит ровно из двух контейнеров:

```text
агенты за NAT ── HTTPS POST ──> внешний nginx ── HTTP ──> app:8080
                                                            │
                                                            ▼
                                                    db (TimescaleDB)
```

- `app` — rootless FrankenPHP, PHP-приложение и фоновые процессы;
- `db` — внутренний PostgreSQL/TimescaleDB без опубликованного порта;
- внешний nginx завершает TLS и проксирует запросы на loopback-порт приложения;
- данные приложения и БД находятся в именованных Docker volumes.

История метрик хранится в TimescaleDB hypertable и continuous aggregates.
Dashboard читает отдельный компактный `current_metric_values`, поэтому его
время ответа не растёт вместе с 60-дневной raw-историей.

## Интерфейс

<p align="center">
  <a href="docs/screenshots/dashboard.webp"><img src="docs/screenshots/dashboard.webp" alt="Дашборд MirvMon с состоянием серверов" width="49%"></a>
  <a href="docs/screenshots/server-detail.webp"><img src="docs/screenshots/server-detail.webp" alt="Карточка сервера с графиками метрик" width="49%"></a>
</p>
<p align="center">
  <a href="docs/screenshots/groups.webp"><img src="docs/screenshots/groups.webp" alt="Управление группами серверов" width="49%"></a>
  <a href="docs/screenshots/notification-settings.webp"><img src="docs/screenshots/notification-settings.webp" alt="Настройки SMTP, Telegram и proxy" width="49%"></a>
</p>

## Запуск через Portainer

1. Создайте Docker Standalone stack из Git-репозитория.
2. Укажите compose path `docker/docker-compose.yml`.
3. Добавьте переменные из `docker/.env.example`.
4. Обязательно задайте три независимых секрета:

```bash
openssl rand -base64 32  # APP_KEY
openssl rand -hex 32     # SETUP_TOKEN
openssl rand -hex 32     # DB_PASSWORD
```

5. Разверните stack и настройте внешний nginx.
6. Откройте `https://ваш-домен/setup`, введите `SETUP_TOKEN` и создайте первого
   администратора.

MirvMon не создаёт стандартного пользователя или пароля. После появления
первого пользователя endpoint `/setup` больше не позволяет создать учётную
запись.

Production Compose загружает готовый образ из `MIRVMON_IMAGE` и не требует
поддержки Docker build со стороны Portainer. Для production рекомендуется
зафиксировать release tag образа вместо `latest`.

Tag `vX.Y.Z` запускает `.github/workflows/release-image.yml`: он публикует
`linux/amd64` и `linux/arm64` image в GHCR с semver-тегами, SBOM и provenance.
Docker image tag не содержит начальную `v`: Git tag `v0.1.0` публикует
`ghcr.io/mirivlad/mirvmon:0.1.0`. Prerelease tag не перезаписывает `latest`.

Подробности: [docker/README.md](docker/README.md).

## Локальная сборка

```bash
cp .env.example .env
# Заполните APP_KEY, SETUP_TOKEN и DB_PASSWORD.

docker compose --env-file .env \
  -f docker/docker-compose.yml \
  -f docker/docker-compose.build.yml \
  up -d --build
```

Либо используйте `docker/deploy.sh`: скрипт создаст `.env` с правами `0600`,
сгенерирует секреты, проверит Compose и запустит те же два сервиса.

## Внешний nginx

Минимальный фрагмент внутри существующего HTTPS `server`:

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

По умолчанию приложение слушает только `127.0.0.1:8080`. Если nginx находится
на другом узле, задайте подходящий `APP_BIND_ADDRESS` и ограничьте доступ к
порту firewall.

`PUBLIC_BASE_URL=https://monitoring.example.com` задаёт канонический публичный
URL. Если переменная пуста, приложение использует scheme и host текущего
запроса только после проверки доверенного reverse proxy.

`SESSION_SECURE=1` включён в production по умолчанию: браузер передаёт
session cookie только через HTTPS. Значение `0` допустимо лишь для изолированной
локальной разработки с прямым HTTP-доступом.

## Проверка состояния и логи

```bash
curl --fail http://127.0.0.1:8080/livez   # HTTP runtime
curl --fail http://127.0.0.1:8080/readyz  # приложение + БД

docker compose -f docker/docker-compose.yml logs -f app
docker compose -f docker/docker-compose.yml logs -f db
```

Контейнерный healthcheck использует `/readyz`. Миграции запускаются при старте
приложения под advisory lock; изменённый checksum уже применённой миграции
считается ошибкой.

## Установка агента

Добавьте сервер в веб-интерфейсе и скачайте один из одноразовых установщиков:
Linux shell, PowerShell, BAT или отдельный установщик для Windows 7 и
Server 2008 R2. Ссылка действует один час и при скачивании
обменивается на постоянный ключ агента. Постоянный ключ не передаётся в URL и в
БД хранится только как SHA-256.

Если задан `PUBLIC_BASE_URL`, установщик привязывается к нему. Иначе используется
scheme, host и port запроса, уже нормализованные middleware доверенного reverse
proxy. В исходниках нет предустановленного домена.

Linux-установщик создаёт непривилегированного пользователя `mirvmon-agent`,
виртуальное окружение с закреплёнными `requests 2.34.2` и `psutil 7.2.2`,
конфигурацию `/etc/mirvmon-agent/config.json` и persistent queue в
`/var/lib/mirvmon-agent`. Агент отправляет данные на публичный HTTPS endpoint и
не принимает входящих соединений. Его состояние и журнал доступны через
systemd:

```bash
sudo systemctl status mirvmon-agent
sudo journalctl -u mirvmon-agent -f
```

### Windows 7 и Server 2008 R2

CPython 3.9 и новее на этих системах не устанавливается, а PowerShell 2.0 не
знает `Invoke-WebRequest`, `ConvertTo-Json` и `Register-ScheduledTask`. Поэтому
для них есть отдельные установщики `/agent/install-legacy.bat` и
`/agent/install-legacy.ps1`: они разворачивают самодостаточный сборщик на
PowerShell 2.0, который читает метрики через WMI, собирает тот же envelope v2
вручную и отправляет его `HttpWebRequest` с принудительно включённым TLS 1.2.
Python не требуется.

Задача планировщика `MirvMon Agent` запускает сборщик раз в минуту через
`schtasks`. Собираются `cpu_load`, `ram_used`, `ram_total_gb`, `uptime`,
`disk_used*`, `net_in_*`/`net_out_*` и службы Windows. Недоставленные замеры
складываются в `%ProgramData%\MirvMon\Agent\queue.txt` (до 200 штук) и
досылаются следующим запуском. Снимок процессов и удалённое управление
конфигурацией агента в этом режиме не поддерживаются. Службы, имя которых не
проходит серверную проверку (пробелы, `$` — например `MSSQL$SQLEXPRESS`),
пропускаются, чтобы не отбраковать весь замер.

Для outbound proxy задайте `HTTPS_PROXY`, `HTTP_PROXY` и при необходимости
`NO_PROXY` в `/etc/default/mirvmon-agent`. TLS-сертификаты проверяются по
умолчанию. При недоступности сервиса bounded disk queue переживает перезапуск,
а повтор одного `sample_id` обрабатывается сервером идемпотентно.

### Протокол отправки

`POST /api/v1/metrics` принимает JSON v2:

```json
{
  "version": 2,
  "sample_id": "018f47a2-8e4c-7d0a-8d8b-45de8fd746a1",
  "sample_time": "2026-07-30T12:00:00Z",
  "token": "<agent-token>",
  "metrics": {"cpu_load": 12.5, "ram_used": 48.1},
  "services": [],
  "process_snapshot": {"top_cpu": [], "top_memory": []}
}
```

Новый sample получает `202`, уже принятый — `200`. Конфигурацию агент
периодически получает исходящим запросом `GET /api/v1/agent/config` с Bearer
token.

## Уведомления

SMTP и Telegram настраиваются в `/admin/notifications`. Доставка не выполняется
в HTTP-запросе и не замедляет приём метрик: событие атомарно записывается в
`notification_outbox`, а `notification-worker` отправляет его в фоне. Повторные
попытки имеют exponential backoff; после десяти ошибок запись переходит в
состояние `dead`.

Для Telegram можно выбрать прямое соединение либо HTTP, HTTPS, SOCKS4, SOCKS4A,
SOCKS5 или SOCKS5H proxy. Хост, порт, логин и пароль задаются отдельными полями;
прокси применяется только к Telegram. Прокси, который слушает на самом хосте
Docker, указывается как `host.docker.internal` — production Compose объявляет
это имя через `host-gateway`. Адрес `127.0.0.1` в этом поле указывает на сам
контейнер и работать не будет. Bot token, SMTP password и proxy password
шифруются authenticated encryption с `APP_KEY`. Веб-интерфейс показывает только
факт наличия секрета: пустое поле сохраняет прежнее значение, а удаление требует
отдельного checkbox.

Кнопка «Сохранить и отправить тест» сохраняет настройки и ставит отдельное
сообщение для каждого включённого канала в ту же очередь. Синхронных внешних
запросов из dashboard нет.

У порога две независимые выдержки. `duration_seconds` требует, чтобы метрика
держалась выше порога заданное время до создания алерта;
`recovery_duration_seconds` — чтобы она держалась ниже порога всё это время до
снятия алерта. По умолчанию восстановление ждёт 300 секунд, поэтому метрика,
колеблющаяся вокруг порога, не превращается в поток «алерт — восстановление —
алерт». Оба значения задаются на сервер и метрику на вкладке порогов, а
значения по умолчанию — в `/admin/defaults`. Ноль в любом из полей означает
немедленную реакцию.

Алерт, снятый вручную на странице `/alerts`, уходит в те же каналы отдельным
событием `alert_resolved` с именем снявшего пользователя — молчаливого
закрытия не происходит. В каждое уведомление о конкретном сервере добавляется
прямая ссылка на его страницу с метриками. Ссылка появляется только при
заданном `PUBLIC_BASE_URL`: notification-worker работает вне HTTP-запроса и
собственный адрес установки узнать не может.

Та же страница показывает состояние фоновых worker: каждый из них отмечается в
`worker_heartbeats` на каждой итерации цикла, и молчание дольше двух минут
выводится красным бейджем. Это отвечает на вопрос «очередь не разбирается
потому, что доставка ломается, или потому, что worker умер».

Доставленные и мёртвые задания очереди не копятся вечно: notification-worker
раз в час удаляет `sent` старше `NOTIFICATION_SENT_RETENTION_DAYS` (7 суток по
умолчанию) и `dead` старше `NOTIFICATION_DEAD_RETENTION_DAYS` (30 суток).
Задания в состояниях `pending`, `processing` и `failed` не удаляются никогда.

Та же страница показывает последние двадцать заданий очереди: канал, статус,
число попыток и причину отказа, которую вернул Telegram или SMTP-сервер
(например `telegram_http_400: chat not found`). Кнопка «Повторить неудачные»
возвращает записи `failed` и `dead` в состояние `pending` с новым бюджетом
попыток — это штатный способ дослать уведомления после исправления токена или
сетевого доступа. Задание для выключенного канала помечается `dead` с причиной
`channel_disabled` и никогда не выдаётся за отправленное.

## Разработка и проверки

```bash
composer install
composer test
composer analyse
composer audit
npm ci
npm run assets:sync
npm audit
PYTHONPATH=agent python3 -m unittest discover -s agent/tests
```

Для schema integration tests требуется отдельная TimescaleDB. Переменные
`TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USERNAME`,
`TEST_DB_PASSWORD` и `TEST_DB_SSLMODE` должны указывать на изолированную
тестовую БД. Без них PHPUnit явно помечает DB-набор как skipped, поэтому release
проверяется только с заданными переменными.

Скомпилированные browser assets хранятся в `public/vendor`, поэтому production
не зависит от npm или внешнего CDN. `package-lock.json` фиксирует build-time
источник; после изменения frontend-зависимостей выполните `npm ci` и
`npm run assets:sync`.

CI в `.github/workflows/ci.yml` проверяет PHP 8.5 на TimescaleDB, PHPStan
level 6, воспроизводимость frontend assets, Python 3.11/3.14 и production
Docker image. Release workflow публикует multi-arch image в GHCR, а Dependabot
следит за Composer, npm, Docker и GitHub Actions.

## Дополнительная документация

- [Архитектура](ARCHITECTURE.md)
- [Техническая спецификация](TECHNICAL_SPECIFICATION.md)
- [Установка](INSTALL.md)
- [Docker и Portainer](docker/README.md)
- [Утверждённый redesign](docs/superpowers/specs/2026-07-30-platform-redesign-design.md)
