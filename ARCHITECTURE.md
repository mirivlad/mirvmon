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
- hypertables: `metric_samples`, `process_snapshots`;
- delivery: `notification_outbox`;
- security/audit support: `login_attempts`, `schema_migrations`.

Числовые метрики находятся в `metric_samples` как `DOUBLE PRECISION`.
Процессы находятся в `process_snapshots` как `JSONB`, поэтому они не загрязняют
числовые агрегаты.

Непрерывные агрегаты:

- `metric_samples_hourly`;
- `metric_samples_daily`.

Raw samples и process snapshots хранятся 60 дней, hourly aggregates — 730
дней. Старые chunks переводятся в columnstore политикой TimescaleDB.

SQL-миграции в `migrations/` применяет `bin/migrate`. Runner удерживает
PostgreSQL advisory lock, выполняет файл транзакционно и сохраняет checksum.

## Агентский поток

Агент собирает CPU, RAM, диски, сеть, температуру, uptime, сервисы и
ограниченный снимок процессов. Он отправляет versioned envelope с UUID sample
ID и UTC timestamp на публичный `POST /api/v1/metrics`.

Целевая последовательность ingestion:

1. проверить agent token и envelope;
2. зарегистрировать `(server_id, sample_id)` для идемпотентности;
3. разрешить metric IDs одним batch-запросом;
4. записать sample rows и optional snapshot;
5. обновить changed service states и `last_metrics_at`;
6. создать alert/outbox rows только при переходе состояния;
7. завершить HTTP без ожидания Telegram/SMTP.

Фоновые процессы выбирают outbox через `FOR UPDATE SKIP LOCKED`, выполняют
доставку с ограниченными retry и обрабатывают offline transitions.

## URL и установщики

Канонический URL берётся из `PUBLIC_BASE_URL`. Если он не задан, URL
определяется по текущему запросу после trusted-proxy normalization. В коде нет
предустановленного домена.

Installer credential одноразовый и короткоживущий. Постоянный agent token
хранится на сервере только как SHA-256 hash. Агент использует outbound HTTPS и
локальную persistent retry queue; входящий сетевой доступ ему не нужен.

## Уведомления

Telegram и SMTP являются независимыми transports. Telegram proxy применяется
только к Telegram channel и поддерживает `http`, `https`, `socks4`, `socks4a`,
`socks5`, `socks5h`. Bot token и proxy password шифруются с использованием
`APP_KEY`; UI не возвращает сохранённые секреты открытым текстом.

## Масштабирование

- dashboard reads выполняются set-based, без запроса на каждую карточку;
- latest values выбираются по индексам времени;
- короткие интервалы читаются из raw hypertable, средние/длинные — из
  continuous aggregates;
- ingestion и notification delivery разделены;
- один `app` контейнер содержит web runtime и управляемые supervisor workers.

При росте нагрузки application image остаётся stateless относительно БД.
Ограничение двумя контейнерами относится к стандартному deployment, поэтому
отдельные broker/cache компоненты не требуются.
