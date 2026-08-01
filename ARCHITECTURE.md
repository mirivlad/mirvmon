# Архитектура MirvMon

## Принципы

- наблюдаемые серверы работают за NAT и инициируют исходящие HTTPS-запросы;
- production deployment состоит ровно из контейнеров `app` и `db`;
- TLS и публичный домен принадлежат внешнему nginx;
- прикладной код не зависит от FrankenPHP, Caddy API или worker mode;
- числовые временные ряды и JSON-снимки процессов хранятся раздельно;
- сетевые уведомления не выполняются в транзакции приёма метрик;
- токены и пароли не имеют встроенных значений по умолчанию.

## Контейнеры и трафик

```text
┌────────────────┐  outbound HTTPS   ┌────────────────┐
│ MirvMon agents ├──────────────────►│ внешний nginx  │
└────────────────┘                   └───────┬────────┘
                                             │ HTTP, trusted proxy headers
                                             ▼
                                    ┌──────────────────┐
браузер ───────── HTTPS ───────────►│ app / FrankenPHP│
                                    └────────┬─────────┘
                                             │ PDO PostgreSQL
                                             ▼
                                    ┌──────────────────┐
                                    │ db / TimescaleDB │
                                    └──────────────────┘
```

`app` слушает container port 8080 и по умолчанию публикуется на
`127.0.0.1:8080`. `db` находится только во внутренней Compose network.

FrankenPHP запускается в classic mode. `public/index.php` только загружает
autoload и вызывает `Bootstrap`; создание контейнера, маршруты и middleware
находятся в `src/Application`.

## HTTP-ядро

Основные уровни:

```text
public/index.php
  └─ Bootstrap
      ├─ Container (ленивые зависимости)
      └─ AppFactory
          ├─ routes/controllers
          ├─ authentication and CSRF
          ├─ request/session limits
          ├─ trusted proxy normalization
          └─ stable error responses
```

Ключевые правила:

- HTML-ошибки и API-ошибки имеют стабильные форматы, детали исключений скрыты;
- каждый ответ получает `X-Request-ID` и security headers;
- executable inline scripts используют уникальный CSP nonce на каждый ответ;
  event-handler attributes и `script-src 'unsafe-inline'` запрещены;
- forwarded host/scheme принимаются только от доверенной сети;
- session ID меняется после входа и первоначальной настройки;
- cookies имеют `HttpOnly`, `SameSite=Lax`, а через HTTPS также `Secure`;
- POST/PUT/PATCH/DELETE защищены серверным CSRF-токеном;
- logout, delete, resolve, token regeneration и test notification не используют
  GET;
- `/api/v1/agent/{id}/processes` требует пользовательскую аутентификацию;
- `/livez` проверяет web runtime, `/readyz` — также БД.

## Данные

PostgreSQL 17 + TimescaleDB 2.28:

- relational: `users`, `server_groups`, `servers`, `metric_names`,
  `metric_thresholds`, `agent_tokens`, `installer_tokens`, `agent_configs`,
  `service_status`, `alerts`, `notification_settings`, `app_settings`;
- idempotency: `ingested_samples`;
- current read model: `current_metric_values`;
- hypertables: `metric_samples`, `process_snapshots`;
- delivery: `notification_outbox`;
- security/audit support: `login_attempts`, `schema_migrations`.

Числовые метрики находятся в `metric_samples` как `DOUBLE PRECISION`.
Процессы находятся в `process_snapshots` как `JSONB`, поэтому они не загрязняют
числовые агрегаты.

`current_metric_values` содержит ровно одну последнюю точку на пару
server/metric. Ingestion обновляет её только более новым `(sample_time,
sample_id)`. Dashboard читает этот компактный read model, а не сканирует
историю hypertable.

Непрерывные агрегаты:

- `metric_samples_hourly`;
- `metric_samples_daily`.

Raw samples и process snapshots хранятся 60 дней, hourly aggregates — 730
дней. Старые chunks переводятся в columnstore политикой TimescaleDB.

SQL-миграции в `migrations/` применяет `bin/migrate`. Runner удерживает
PostgreSQL advisory lock, выполняет файл транзакционно и сохраняет checksum.
Healthcheck БД отличает временный postmaster этапа `initdb/tune` от основного
процесса PID 1, поэтому приложение не стартует в коротком окне перезапуска
TimescaleDB.

## Агентский поток

Агент собирает CPU, RAM, диски, сеть, температуру, uptime, изменившиеся
состояния сервисов и ограниченный снимок процессов. Он отправляет envelope
`version=2` с UUID `sample_id`, UTC `sample_time`, числовыми `metrics` и
credential в HTTPS body на публичный `POST /api/v1/metrics`.

Целевая последовательность ingestion:

1. проверить agent token и envelope;
2. зарегистрировать `(server_id, sample_id)` для идемпотентности;
3. разрешить metric IDs одним batch-запросом;
4. записать sample rows и optional snapshot;
5. обновить changed service states и `last_metrics_at`;
6. создать alert/outbox rows только при переходе состояния;
7. завершить HTTP без ожидания Telegram/SMTP.

Фоновые процессы выбирают outbox через `FOR UPDATE SKIP LOCKED`, выполняют
доставку с ограниченными retry и обрабатывают offline transitions. При разрыве
соединения с БД worker отбрасывает весь PDO-dependent object graph и создаёт
новое соединение после паузы; неизвестная программная ошибка завершает процесс
для контролируемого restart через supervisor.

## URL и установщики

Канонический URL берётся из `PUBLIC_BASE_URL`. Если он не задан, URL
определяется по текущему запросу после trusted-proxy normalization. В коде нет
предустановленного домена.

Для Linux, PowerShell и BAT создаются независимые installer credentials:
каждый одноразовый и действует один час. При скачивании credential атомарно
обменивается на новый permanent agent token, после чего повторное скачивание
невозможно. Исходники агента публичны и скачиваются без credential; permanent
token хранится на сервере только как SHA-256 hash и никогда не попадает в query
string.

Агент использует outbound HTTPS, стандартные proxy environment variables и
локальную bounded persistent retry queue; входящий сетевой доступ ему не нужен.
На Linux процесс работает под отдельным `mirvmon-agent`, на Windows code и
state разделены между `Program Files` и `ProgramData`.

Linux runtime совместим с CPython 3.6–3.14 на x86-64. HTTP transport реализован
на `urllib` и системном CA store; он поддерживает proxy environment, ограничивает
response body и не передаёт Bearer credential при redirect на другой origin.
Installer готовит release в staging, проверяет `compileall` и `--check`, затем
атомарно переключает launcher. systemd выбирается только когда он PID 1; иначе
используется SysVinit с LSB service script. `/etc/default/mirvmon-agent` — файл
environment на Debian/MX, `/etc/sysconfig/mirvmon-agent` — на RHEL-подобных ОС.

## Уведомления

Telegram и SMTP являются независимыми transports. Telegram proxy применяется
только к Telegram channel и поддерживает `http`, `https`, `socks4`, `socks4a`,
`socks5`, `socks5h`. Bot token и proxy password шифруются с использованием
`APP_KEY`; тем же ключом защищён SMTP password. UI не возвращает сохранённые
секреты открытым текстом. SMTP и Telegram используют проверку TLS и ограниченные
connect/request timeouts. Тестовая отправка проходит через тот же outbox и
worker, что production-события.

## Масштабирование

- dashboard reads выполняются set-based, без запроса на каждую карточку;
- current values читаются из `current_metric_values`, поэтому объём истории не
  влияет на dashboard query;
- интервалы до 48 часов читаются из raw hypertable, до 90 дней — из hourly
  aggregate, более длинные — из daily aggregate;
- ingestion и notification delivery разделены;
- один `app` контейнер содержит web runtime и управляемые supervisor workers.

Bootstrap, Font Awesome, Chart.js, Hammer.js и chart zoom plugin фиксируются
через npm lockfile, но готовые bundles входят в image и обслуживаются самим
приложением. Production-контейнер не содержит Node.js и не зависит от CDN.
Persistent `/app/var` используется для runtime-state; Twig cache очищается
entrypoint при старте нового контейнера, чтобы не переживать обновление image.

`bin/benchmark-dashboard` воспроизводимо проверяет set-based query на 50 и
1000 синтетических серверах. Все benchmark fixtures создаются в транзакции и
откатываются.

При росте нагрузки application image остаётся stateless относительно БД.
Ограничение двумя контейнерами относится к стандартному deployment, поэтому
отдельные broker/cache компоненты не требуются.
