# AGENTS.md — правила работы с MirvMon

## Назначение и границы

MirvMon — self-hosted система push-мониторинга серверов. Проект находится в
активной разработке, production-пользователей и совместимых legacy-инсталляций
нет. Не сохраняйте устаревшую схему или API ценой усложнения новой архитектуры,
но обновляйте документацию и тесты вместе с каждым изменением поведения.

Публичный домен не задан в исходниках. Канонический URL берётся из
`PUBLIC_BASE_URL`, а при пустом значении — из origin доверенного HTTP-запроса.
Никогда не добавляйте в код, тестовые данные или документацию реальный домен
развёртывания.

## Поддерживаемая архитектура

- PHP 8.5, Slim Framework 4, Twig 3;
- PostgreSQL 17 + TimescaleDB 2.28;
- FrankenPHP в classic mode как основной HTTP adapter;
- внешний nginx завершает TLS;
- Go 1.26.6 для современного агента и Go 1.20.14 для Windows 7/8/8.1 и
  Server 2008 R2/2012/2012 R2;
- production Compose содержит ровно два сервиса: `app` и `db`.

Расширение `gd` и пакет `fonts-dejavu-core` нужны для графика в уведомлении:
подписи на кириллице рисуются TrueType-шрифтом. NSIS 3 (`makensis`) собирает
персонализированный Windows EXE. Проверка runtime-зависимостей при старте
находится в `docker/entrypoint.sh`.

FrankenPHP — только способ запуска. Прикладной код должен использовать
PSR/PDO-границы и оставаться переносимым на nginx + PHP-FPM. Не используйте
Caddy API, FrankenPHP API, worker mode или долгоживущее глобальное состояние в
контроллерах и сервисах.

Агент работает за NAT и только сам инициирует HTTPS-запросы. Не добавляйте
обратный polling или требование входящего порта агента.

## Основные каталоги

```text
agent/          нативный Go-агент и его тесты
bin/            миграции, workers и benchmark
docker/         production Compose, image и Portainer-инструкции
migrations/     checksum-protected PostgreSQL/TimescaleDB migrations
public/         front controller и self-hosted browser assets
src/            HTTP, domain, repositories, services и workers
templates/      Twig UI
tests/          unit, integration, functional и contract tests
```

## Данные и фоновые задачи

- `metric_samples` и `process_snapshots` — hypertables;
- `current_metric_values` — компактный read model для текущего состояния;
- `metric_samples_hourly` и `metric_samples_daily` — continuous aggregates;
- `notification_outbox` отделяет приём метрик от Telegram/SMTP;
- `maintenance_windows` подавляет доставку, но не создание алертов;
- `bin/offline-worker` вычисляет offline transitions;
- `bin/notification-worker` доставляет outbox jobs и раз в час чистит очередь;
- оба worker отмечаются в `worker_heartbeats` на каждой итерации;
- SQL применяет `bin/migrate` под PostgreSQL advisory lock.

Не создавайте параллельные schema dumps и не редактируйте уже применённую
миграцию. Новое изменение схемы оформляется новым SQL-файлом. Так как проект
ещё не выпущен, согласованное схлопывание миграций допустимо только отдельной
задачей с обновлением тестов и документации.

## Конфигурация

Production-переменные перечислены в `.env.example` и `docker/.env.example`.
Обязательные секреты:

- `APP_KEY` — base64 от 32 случайных байт;
- `SETUP_TOKEN` — случайная строка не короче 32 символов;
- `DB_PASSWORD` — случайная строка не короче 16 символов.

Подключение к БД задаётся `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USERNAME`,
`DB_PASSWORD`, `DB_SSLMODE`. Секреты не имеют значений по умолчанию и не
фиксируются в Git. Для штатного HTTPS deployment сохраняйте
`SESSION_SECURE=1`; отключать его можно только в изолированной прямой
HTTP-разработке.

Telegram и SMTP настраиваются в `/admin/notifications`. Telegram proxy
поддерживает HTTP, HTTPS, SOCKS4, SOCKS4A, SOCKS5 и SOCKS5H и применяется
только к Telegram transport. Bot token, SMTP password и proxy password
хранятся зашифрованно с `APP_KEY`.

## Публичные HTTP endpoints

- `POST /api/v1/metrics` — идемпотентный приём envelope v2;
- `GET /api/v1/agent/config` — конфигурация по Bearer credential;
- `GET /agent/binaries/{artifact}` — публичная раздача проверенного нативного
  артефакта (`linux-amd64`, `windows-amd64`, `windows-legacy-amd64`);
- `GET /agent/install.sh?token=...` — одноразовый Linux installer;
- `GET /agent/install.exe?token=...` — единый персонализированный неподписанный
  Windows x64 installer с автоматическим выбором modern/legacy agent;
- `POST /api/v1/agent/install` — одноразовый обмен installer Bearer credential
  на постоянную конфигурацию выбранным нативным Windows agent;
- `GET /livez` — только HTTP runtime;
- `GET /readyz` — приложение и БД.

Остальные UI/API endpoints требуют пользовательской сессии; administrative
routes дополнительно требуют роли `admin`. Все изменяющие browser-запросы
защищены CSRF и не должны переводиться на GET.

## Локальная разработка

```bash
composer install
npm ci
npm run assets:sync

cp .env.example .env
# Заполнить APP_KEY, SETUP_TOKEN и DB_PASSWORD.
docker compose --env-file .env \
  -f docker/docker-compose.yml \
  -f docker/docker-compose.build.yml \
  up -d --build
```

Production Compose использует готовый `MIRVMON_IMAGE`; build overlay нужен
только для исходников. БД не публикует порт, а приложение по умолчанию
привязано к `127.0.0.1`.

Release image публикует `.github/workflows/ci.yml` на semver-тегах
`vX.Y.Z`/prerelease; helper `.github/workflows/release.yml` создаёт tag из
ветки `release/v...` и запускает tag CI. Не публикуйте floating production release без
успешного полного CI.

## Обязательные проверки

Для изменения PHP:

```bash
composer test
composer analyse
composer validate --strict
composer audit
```

Для агента:

```bash
(cd agent && go test ./...)
(cd agent && go test -race ./...)
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 \
  go -C agent build -trimpath -buildvcs=false ./cmd/mirvmon-agent
```

Для frontend и deployment:

```bash
npm ci
npm run assets:sync
git diff --exit-code -- public/vendor
npm audit --audit-level=high
shellcheck docker/*.sh
docker compose --env-file .env -f docker/docker-compose.yml config --quiet
docker build -f docker/Dockerfile .
```

Integration suite требует чистую TimescaleDB и переменные `TEST_DB_HOST`,
`TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USERNAME`, `TEST_DB_PASSWORD`,
`TEST_DB_SSLMODE`. Не называйте интеграционный прогон полным, если эти тесты
были пропущены.

Перед завершением архитектурной или UI-задачи также выполните:

- browser smoke test desktop и viewport 390 px без console errors;
- `bin/benchmark-dashboard` для 50 и 1000 серверов при изменении dashboard
  query;
- clean двухконтейнерный старт и проверки `/livez`, `/readyz`.

## Безопасность изменений

- не логируйте agent token, installer token, proxy credentials и ключи;
- permanent agent token хранится только как SHA-256 hash и не передаётся в URL;
- installer credential одноразовый и ограничен по времени;
- не возвращайте SQL/exception details в production responses;
- не ослабляйте CSP, CSRF, session rotation, trusted-proxy checks или TLS
  verification ради удобства;
- не добавляйте CDN-зависимости: production assets находятся в
  `public/vendor`.

Главные документы: `README.md`, `docs/README.md`, `docs/agent.md`,
`docs/troubleshooting.md`, `ARCHITECTURE.md`, `TECHNICAL_SPECIFICATION.md`,
`INSTALL.md`, `docker/README.md`.
