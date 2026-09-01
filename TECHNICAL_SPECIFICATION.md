# Техническая спецификация MirvMon

## Назначение

MirvMon принимает push-метрики серверов, отображает их текущее и историческое
состояние, создаёт алерты и доставляет уведомления. Система предназначена для
публичной публикации и собственного production-использования, а не для
одноразового прототипа.

## Обязательный стек

| Слой | Требование |
|---|---|
| Runtime | PHP 8.5, Slim 4, Twig 3 |
| Primary HTTP adapter | FrankenPHP 1.12 classic mode |
| Database | PostgreSQL 17 + TimescaleDB 2.28 |
| Agent | native Go: 1.26.5 для modern x64; 1.20.14 для Windows 7/8/8.1 и Server 2008 R2/2012/2012 R2 x64 |
| UI | Bootstrap 5, Chart.js 4, локально закреплённые assets |
| Deployment | два контейнера: `app`, `db` |
| TLS | внешний nginx reverse proxy |

Application/domain code должен оставаться переносимым на nginx + PHP-FPM.

## Функциональные требования

### Пользователи и безопасность

- стандартные логин и пароль отсутствуют;
- первый administrator создаётся через `/setup` с `SETUP_TOKEN`;
- после создания первого пользователя повторный bootstrap запрещён;
- password hashing использует `password_hash()` и автоматический rehash;
- login attempts ограничиваются по username и HMAC источника;
- session ID меняется после login/setup;
- состояние изменяется только небезопасными HTTP verbs с CSRF;
- API возвращает JSON 4xx/5xx без stack trace и секретных значений;
- forwarded headers доверяются только явно заданным proxy networks.

### Серверы и группы

- CRUD групп и серверов;
- per-server offline timeout и notification policy;
- per-metric warning/critical threshold и duration;
- управление monitored services;
- отображение current state, active alerts и last sample time.

### Метрики

Protocol v2 envelope должен содержать:

- `version=2`;
- `sample_id` — UUID;
- `sample_time` — UTC timestamp, не старше семи дней и не более чем на пять
  минут в будущем;
- `token` — agent credential только в HTTPS body;
- `agent_version` и `os_version` — версии агента и ОС, если агент их знает;
- `agent_artifact` — allowlisted build key, а `agent_capabilities` — bounded
  список возможностей; `v0.4.3+` сообщает `self_update_v1`;
- `metrics` — от 1 до 100 конечных чисел с именами
  `^[a-z][a-z0-9_]{0,99}$`;
- optional `process_snapshot` не более 64 KiB, до 20 процессов в каждом top;
- optional `services` — до 500 изменившихся состояний.

Повторная доставка одного sample не создаёт повторных rows, alerts или
notifications. Запоздалый допустимый sample записывается в историю, но не
откатывает `current_metric_values`, состояние сервисов или алерты. Время сервера
не заменяет accepted sample timestamp.

### Агент

- только исходящие HTTPS-запросы, без polling агента извне;
- URL сервиса задаётся `PUBLIC_BASE_URL` либо origin установщика;
- Linux и Windows installers;
- отдельный непривилегированный пользователь на Linux;
- TLS verification включена по умолчанию;
- proxy environment поддерживается HTTP client;
- persistent retry queue ограничена 1000 пакетами, записывается атомарно с
  режимом `0600` и переживает restart;
- Linux installer поддерживает systemd x64 Debian 11+, Ubuntu 20.04+,
  CentOS/RHEL/Oracle Linux 7+, AlmaLinux/Rocky Linux 8+ и NethServer 7;
- Windows 10/11 и Server 2016/2019/2022/2025 используют Go 1.26.5; Windows 7
  SP1/8/8.1 и Server 2008 R2 SP1/2012/2012 R2 — Go 1.20.14; Windows Server
  2008 без R2 и x86 системы исключены;
- единый неподписанный Windows EXE собирается NSIS на сервере и содержит обе
  catalog-verified x64-сборки, защищённый `bootstrap.json` и одноразовый
  часовой credential, но не permanent agent token и не PowerShell/BAT;
- NSIS выбирает совместимый EXE и напрямую вызывает `install-windows`;
  выбранный Go-процесс получает конфигурацию через HTTPS и выполняет всю
  локальную транзакцию;
- native installer назначает ACL через well-known SID `S-1-5-18` и
  `S-1-5-32-544`, проверяет все критические Windows API/native operations и
  сохраняет старый runtime неизменным до успешных `check` и `migrate`;
- commit legacy-установки сохраняет timestamped backups, проверяет Windows
  service `Running` через SCM и выполняет rollback файлов/service metadata при
  post-commit ошибке;
- старые EOL ОС поддерживаются только как compatibility target агента и не
  считаются безопасными production platform;
- конфигурация и queue записываются атомарно с ограниченными правами;
- `health.json` рядом с queue хранит безопасный operator-visible state, времена
  последней коллекции/доставки и очищенный `last_error`;
- transport failures классифицируются как `authentication_error`, `dns_error`,
  `network_timeout`, `network_error`, `tls_error`, `server_error`,
  `configuration_error` или `runtime_error`;
- логи и диагностические сообщения не содержат token, proxy credential или URL
  query secrets.

Удалённое обновление:

- первый capable release — `v0.4.3`; старые версии требуют одно ручное
  обновление установщиком;
- admin создаёт команду через CSRF-protected
  `POST /servers/{id}/agent/update`;
- агент получает её только через outbound authenticated config poll и сообщает
  прогресс в `POST /api/v1/agent/update/{command}/status`;
- command schema не допускает arbitrary URL, path или executable text;
- target version должна быть строго новее, artifact обязан совпадать с build
  identity, а файл — с manifest size и SHA-256;
- Linux применяет замену root one-shot unit, Windows — trusted LocalSystem
  helper; config, token и metrics queue остаются неизменными;
- startup/health failure восстанавливает `.previous`; успех подтверждается
  новым metrics envelope с target version (либо более новой) и точным artifact; это подтверждение
  завершает любую незакрытую стадию, включая ручную установку target version;
- уже установленная target version не прерывает metrics cycle, а stale local
  update state переводится в terminal перед следующим обновлением;
- при config poll устаревшая команда атомарно завершается только в состоянии
  `pending`: row получает
  `target_superseded`, но новый UUID автоматически не создаётся; после local
  cleanup требуется явный retry администратора, а состояния `accepted` и далее
  автоматически не меняются.

### Мониторинг сайтов

- website checks выполняются централизованно внутри `app`; native agent не
  выполняет HTTP(S)-probes;
- production Compose не получает дополнительный service или входящий порт;
- один сайт может содержать несколько HTTP(S) endpoints;
- endpoint поддерживает expected status ranges, redirect policy, text assertion,
  дополнительные headers, explicit auth, timeout и пороги TTFB/total response;
- TLS check проверяет hostname, issuer и срок действия сертификата; self-signed
  сертификат разрешается только явной настройкой и остаётся operator-visible;
- domain expiry определяется через RDAP с ограниченным WHOIS fallback;
- internal/private targets допускаются только в trusted-admin модели и проходят
  проверки SSRF и redirect destinations;
- response bodies и credentials не сохраняются в history;
- current state хранится отдельно от raw history, чтобы dashboard/list views не
  сканировали hypertable;
- HTTP transport, assertions, performance, TLS и domain problems создают обычные
  incidents и используют существующие maintenance/notification semantics;
- maintenance подавляет delivery, но не скрывает факт события;
- detail page разделяет overview, metrics, events и settings;
- metrics включают transport availability, assertion success, TTFB и total
  response time с периодами от часа до года.

### Алерты и уведомления

- состояния `ok`, `warning`, `critical`, `offline` меняются только по явным
  transitions;
- ingestion записывает событие в transactional outbox;
- workers выполняют отправку асинхронно с retries и `SKIP LOCKED`;
- после restart/недоступности БД workers пересоздают PDO-зависимости, не
  зацикливаются на разорванном соединении;
- после десяти неудачных попыток outbox row переходит в `dead`;
- Telegram и SMTP настраиваются в dashboard;
- Telegram proxy: HTTP, HTTPS, SOCKS4, SOCKS4A, SOCKS5, SOCKS5H;
- bot/proxy/SMTP secrets шифруются, не отображаются обратно и очищаются только
  явным действием.
- соединения проверяют TLS certificate и имеют connect/request timeouts;
- тест из dashboard сохраняет форму и создаёт обычные outbox jobs без
  синхронного сетевого вызова;
- `NOTIFICATION_POLL_INTERVAL` и `NOTIFICATION_BATCH_SIZE` ограничены и
  валидируются worker при старте.

### Dashboard

- summary и server cards используют единый status algorithm;
- current metric — последняя по sample timestamp;
- загрузка dashboard имеет bounded query count;
- responsive layout работает от 390 px без обрезания значений;
- status выражен текстом, цвет не является единственным сигналом;
- доступны search/filter/sort и относительное время;
- frontend не зависит от CDN;
- frontend-зависимости закреплены в `package-lock.json`, а production bundle
  обслуживается из `public/vendor`;
- inline scripts разрешаются только по уникальному CSP nonce; inline event
  handlers и `script-src 'unsafe-inline'` не используются;
- при старте нового image persistent Twig cache очищается до запуска web
  runtime, поэтому redeploy не оставляет старые шаблоны.

## Хранение данных

- `metric_samples` и `process_snapshots` — Timescale hypertables;
- `website_check_samples` хранит raw историю HTTP(S)-проверок;
- `website_state` и `website_endpoint_state` являются компактными current read models;
- website raw history хранится 30 дней, агрегированная история — не менее 365 дней;
- `current_metric_values` — компактная последняя точка каждой server/metric
  пары для dashboard и detail summary;
- raw/process retention — 60 дней;
- hourly aggregate retention — 730 дней;
- daily aggregate хранит долгосрочные trends;
- columnstore policy применяется к закрытым chunks;
- история индексируется по server, metric и descending sample time;
- dashboard query не читает `metric_samples` и не зависит от размера истории.

## Deployment contract

Production Compose:

- содержит только `app` и `db`;
- не публикует PostgreSQL port;
- запускает `app` rootless с read-only filesystem, dropped capabilities и
  resource limits;
- по умолчанию устанавливает `SESSION_SECURE=1` для TLS на внешнем nginx;
- хранит persistent данные в volumes;
- требует `APP_KEY`, `SETUP_TOKEN`, `DB_PASSWORD`;
- использует pinned base/database images;
- healthcheck приложения вызывает `/readyz`;
- выполняет checksum-protected migrations при старте.

Portainer использует готовый `MIRVMON_IMAGE`. Локальная разработка добавляет
`docker/docker-compose.build.yml`.

## Quality gates

Обязательные проверки перед release:

```bash
composer test
composer analyse
composer validate --strict
composer audit
shellcheck docker/*.sh
docker compose config
docker build -f docker/Dockerfile .
```

PHPStan выполняется на уровне 6 без baseline и suppressions. GitHub Actions
повторяет PHP/TimescaleDB, frontend, agent и container checks; используемые
actions закреплены полными commit SHA.

Release tags `vX.Y.Z` публикуют multi-arch image в GHCR с SBOM и provenance.
Prerelease tags не обновляют `latest`.

Schema integration tests выполняются на чистой TimescaleDB и повторно после
всех миграций. Agent tests выполняются Go 1.26.5 и Go 1.20.14.
Dashboard проверяется browser tests на desktop и mobile viewport.

## Критерии приёмки

- clean two-container deployment становится healthy без ручного SQL;
- первый admin создаётся только с setup token;
- агент за NAT отправляет метрики через внешний HTTPS endpoint;
- duplicate envelope идемпотентен;
- website monitoring работает без отдельного Compose service и без изменения
  agent protocol;
- HTTP(S) endpoint failure создаёт incident и recovery через общий pipeline;
- недоступный Telegram не увеличивает latency ingestion;
- current status согласован на summary, cards и details;
- proxy credentials и application secrets отсутствуют в HTTP responses/logs;
- документация соответствует release compose и environment variables.
