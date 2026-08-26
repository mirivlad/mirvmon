# Агент MirvMon

Этот документ — эксплуатационная справка по нативному агенту MirvMon: где он
установлен, где лежит конфигурация и очередь, какие команды доступны и как
диагностировать проблемы доставки метрик.

## Как работает агент

Агент сам инициирует исходящие HTTPS-запросы к MirvMon. Входящий порт на
наблюдаемом сервере не требуется.

Основной цикл:

1. получить remote config через `GET /api/v1/agent/config`;
2. собрать локальные метрики;
3. атомарно положить замер в дисковую очередь;
4. отправить старейший элемент через `POST /api/v1/metrics`;
5. при временной ошибке оставить элемент в очереди и повторить позже.

По умолчанию интервал — 60 секунд, очередь ограничена 1000 элементами.
Повторная доставка того же `sample_id` безопасна: сервер обрабатывает её
идемпотентно.

## Поддерживаемые системы

Все официальные сборки — x64.

- Linux: Debian 11+, Ubuntu 20.04+, CentOS/RHEL/Oracle Linux 7+,
  AlmaLinux/Rocky Linux 8+, NethServer 7 и современные преемники с systemd;
- Windows 10/11 и Windows Server 2016/2019/2022/2025 — современная сборка;
- Windows 7 SP1, 8, 8.1 и Windows Server 2008 R2 SP1, 2012, 2012 R2 — legacy
  Windows build;
- Windows Server 2008 без R2 и 32-битные системы не поддерживаются.

## Linux: файлы и service

Стандартная установка использует:

| Назначение | Путь |
| --- | --- |
| Бинарник | `/opt/mirvmon-agent/mirvmon-agent` |
| Конфигурация | `/etc/mirvmon-agent/config.json` |
| Очередь метрик | `/var/lib/mirvmon-agent/queue.json` |
| Состояние/диагностика | `/var/lib/mirvmon-agent/health.json` |
| Состояние self-update | `/var/lib/mirvmon-agent/update-state.json` |
| Запрос self-update | `/var/lib/mirvmon-agent/update-request.json` |
| systemd service | `mirvmon-agent.service` |
| privileged update path | `mirvmon-agent-update.path` |
| privileged updater | `mirvmon-agent-update.service` |

Основной процесс работает от отдельного пользователя `mirvmon-agent`.

Базовые команды:

```bash
sudo systemctl status mirvmon-agent --no-pager -l
sudo journalctl -u mirvmon-agent -n 100 --no-pager
sudo systemctl restart mirvmon-agent
```

## Windows: файлы и service

Стандартная установка использует:

| Назначение | Путь |
| --- | --- |
| Бинарник | `%ProgramFiles%\MirvMon\Agent\mirvmon-agent.exe` |
| Конфигурация | `%ProgramData%\MirvMon\Agent\config.json` |
| Очередь метрик | `%ProgramData%\MirvMon\Agent\queue.json` |
| Состояние/диагностика | `%ProgramData%\MirvMon\Agent\health.json` |
| Service name | `MirvMonAgent` |

Service работает от LocalSystem. Проверка:

```cmd
sc.exe query MirvMonAgent
```

## Команды агента

### `version`

Показывает версию, commit, платформу и artifact:

```bash
/opt/mirvmon-agent/mirvmon-agent version
```

Пример:

```text
v0.4.21 unknown linux/amd64 linux-amd64
```

### `check`

Проверяет локальную конфигурацию и возможность открыть очередь, но не собирает
и не отправляет метрики:

```bash
sudo -u mirvmon-agent \
  /opt/mirvmon-agent/mirvmon-agent check \
  --config /etc/mirvmon-agent/config.json
```

С `--server` дополнительно выполняется аутентифицированный запрос remote config:

```bash
sudo -u mirvmon-agent \
  /opt/mirvmon-agent/mirvmon-agent check \
  --config /etc/mirvmon-agent/config.json \
  --server
```

Это первая команда при подозрении на проблемы связи агента с MirvMon.

### `once`

Выполняет один рабочий цикл. С `--require-delivery` команда считается успешной
только если после цикла очередь не требует дальнейшей доставки:

```bash
sudo -u mirvmon-agent \
  /opt/mirvmon-agent/mirvmon-agent once \
  --config /etc/mirvmon-agent/config.json \
  --require-delivery
```

### `run`

Запускает постоянный цикл агента. Обычно вручную эту команду не используют — её
запускает systemd или Windows Service Manager.

```bash
/opt/mirvmon-agent/mirvmon-agent run \
  --config /etc/mirvmon-agent/config.json
```

### Служебные команды установщика

`migrate`, `activate`, `install-windows` и `apply-update` являются частью
транзакционной установки/обновления. Для обычной эксплуатации их вручную
запускать не требуется.

## Коды завершения

| Код | Значение |
| ---: | --- |
| `0` | успешно |
| `1` | runtime/local failure |
| `2` | неверные аргументы или локальная конфигурация |
| `3` | доставка/аутентификация/remote state не позволяют завершить действие; сюда относятся DNS/network/TLS/server failures |

Для диагностики важны одновременно код возврата и текст ошибки.

## Конфигурация

`config.json` содержит следующие поддерживаемые поля:

| Поле | Назначение |
| --- | --- |
| `api_url` | endpoint `POST /api/v1/metrics` |
| `config_url` | endpoint `GET /api/v1/agent/config` |
| `token` | permanent agent credential; секрет |
| `queue_path` | путь к durable queue |
| `interval_seconds` | интервал, 10–86400 секунд |
| `verify_tls` | проверка TLS; по умолчанию `true` |
| `collect_process_commands` | передавать command line процессов |
| `enabled` | локальное включение агента |
| `monitor_services` | список контролируемых сервисов |
| `queue_limit` | размер очереди, 1–10000; по умолчанию 1000 |

Не публикуйте `token` в issue, чатах и логах. Для показа конфигурации безопаснее
редактировать его перед копированием, например:

```bash
sudo sed -E \
  's/("token"[[:space:]]*:[[:space:]]*")[^"]+/\1***REDACTED/' \
  /etc/mirvmon-agent/config.json
```

Remote config может менять `enabled`, `interval_seconds`, `monitor_services` и
передавать строго типизированную команду self-update.

## `health.json`

Агент сохраняет рядом с очередью короткий operator-visible статус. Пример:

```json
{
  "agent_version": "v0.4.22",
  "commit": "0123456789abcdef",
  "started_at": "2026-08-26T00:00:00Z",
  "last_collection_at": "2026-08-26T00:01:00Z",
  "last_delivery_at": "2026-08-26T00:01:00Z",
  "state": "accepted",
  "last_error": ""
}
```

Linux:

```bash
sudo cat /var/lib/mirvmon-agent/health.json
```

Поле `last_error` проходит дополнительную очистку от credential-shaped значений.

## Состояния диагностики ошибок

Новые версии агента классифицируют сетевые и серверные ошибки вместо того, чтобы
называть любой сбой remote config `authentication_error`.

| State | Что означает |
| --- | --- |
| `authentication_error` | сервер вернул 401/403 для agent credential |
| `dns_error` | resolver явно сообщил DNS failure |
| `network_timeout` | истёк timeout DNS/TCP/HTTP операции |
| `network_error` | другая ошибка сетевого transport |
| `tls_error` | ошибка TLS handshake или проверки сертификата |
| `server_error` | неожиданный HTTP status/response или некорректный remote config |
| `configuration_error` | локально запрещённая transport-конфигурация или redirect policy |
| `runtime_error` | ошибка, не относящаяся к перечисленным категориям |

Состояния нормальной работы включают `queued`, `accepted`, `disabled`,
`collection_error`, `rejected` и служебные состояния update flow.

Один общий timeout не всегда позволяет достоверно отличить DNS timeout от TCP
или HTTP timeout; в таком случае используется `network_timeout`. Для точного
разделения применяйте команды из [Troubleshooting](troubleshooting.md).

## Proxy

HTTP transport агента использует стандартные `HTTP_PROXY`, `HTTPS_PROXY` и
`NO_PROXY`.

Для systemd рекомендуется override:

```bash
sudo systemctl edit mirvmon-agent
```

```ini
[Service]
Environment="HTTPS_PROXY=http://proxy.example:3128"
Environment="HTTP_PROXY=http://proxy.example:3128"
Environment="NO_PROXY=127.0.0.1,localhost"
```

Затем:

```bash
sudo systemctl daemon-reload
sudo systemctl restart mirvmon-agent
```

На Windows переменные должны быть доступны окружению service process; после их
изменения перезапустите `MirvMonAgent`.

## TLS

Проверка TLS включена по умолчанию. Агент использует системный набор доверенных
корневых сертификатов и не должен отключать проверку для обычного HTTPS.
Небезопасный режим разрешён только для loopback HTTP, предназначенного для
локальных тестов.

## Self-update

Начиная с `v0.4.3`, агент умеет принимать строго типизированную команду
self-update через существующий исходящий config poll. Администратор выдаёт
команду в UI; агент не принимает произвольные shell-команды, URL или пути.

На Linux непривилегированный агент подготавливает фиксированный request, а
root-owned `mirvmon-agent-update.path/service` выполняет замену. На Windows
обновление выполняется защищённым LocalSystem flow. Старый бинарник сохраняется
как `.previous`, а неуспешный запуск должен приводить к rollback.

Диагностика Linux updater:

```bash
sudo systemctl status mirvmon-agent-update.path mirvmon-agent-update.service
sudo journalctl -u mirvmon-agent-update.service -n 100 --no-pager
```

Одноразовые процедуры восстановления для конкретных старых релизов относятся к
[troubleshooting.md](troubleshooting.md), а не к основной документации агента.
