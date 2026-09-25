# Распределённые проверки сайтов

MirvMon может использовать уже установленные серверные агенты как удалённые точки HTTP(S)-проверки. Отдельный probe daemon и входящие соединения к агенту не требуются.

## Модель

Для каждого сайта **Central MirvMon всегда выполняет центральную проверку**. Дополнительно можно выбрать ноль или несколько серверов, чьи агенты разрешены как удалённые точки transport HTTP(S)-проверки.

**Failure quorum** — число точек, включая Central MirvMon и выбранные агенты, которые должны видеть transport failure, чтобы агрегированная transport-проверка считалась неуспешной.

Без выбранных агентов сайт работает как обычная централизованная проверка с quorum `1`.
## Поток данных

0. Агент должен быть обновлён до версии с capability `website_probe_v1`; старым агентам сервер не отправляет новые поля remote config.
1. Администратор включает на сервере «Использовать агент как точку проверок сайтов».
2. Сайт назначает этот агент как probe point.
3. `GET /api/v1/agent/config` возвращает агенту только назначенные ему `probe_jobs` и детерминированный `probe_revision`.
4. Агент выполняет due HTTP(S)-jobs исходящими соединениями.
5. Результаты попадают в обычный metrics envelope и в ту же bounded durable queue.
6. `POST /api/v1/metrics` аутентифицирует агента и принимает observation только для всё ещё действующего назначения.
7. MirvMon хранит central samples в прежнем `website_check_samples`, remote observations — отдельно в `website_probe_samples`, вычисляет кворум и передаёт агрегированное transport-состояние существующему state machine `3 failures / 2 successes`.

Отключённое или удалённое назначение не принимает запоздалые queued results.
## NAT и безопасность

Агент не открывает listener. И получение конфигурации, и отправка результатов инициируются агентом наружу, поэтому probe point может находиться за NAT/CGNAT и не требует DNAT/port-forward.

Удалённому агенту передаются только URL и безопасные параметры transport-проверки. MirvMon **не отправляет** удалённым точкам:
- endpoint authentication secrets;
- custom headers;
- разрешение self-signed TLS;
- response-body assertions.

Endpoint с authentication, custom headers или `allow_self_signed` не включается в remote config.
TLS certificate expiry, domain registration checks, content/status assertions и performance assertions являются централизованными функциями MirvMon и не выполняются агентами. Remote HTTP response, включая 4xx/5xx, означает успешный transport response; семантика ожидаемого HTTP status оценивается центральным checker.

TLS verification на агенте включена всегда. URL credentials запрещены, response bodies и secrets не сохраняются и не отправляются обратно.

## Кворум и свежесть

Для каждого endpoint MirvMon берёт последний свежий transport result от каждой выбранной точки. Observation считается свежим максимум три configured intervals, но не меньше 120 секунд. Старый результат перестаёт участвовать в кворуме при следующем пересчёте.

Отсутствующая точка не считается автоматически failure сайта: её собственная недоступность не должна превращать доступный сайт в outage. Диагностика показывает число фактически reporting points.
Пример для трёх точек и quorum `2`:

| Central | Agent A | Agent B | Aggregate |
| --- | --- | --- | --- |
| fail | ok | ok | available |
| fail | fail | ok | unavailable |
| fail | fail | no data | unavailable |
| ok | no data | no data | available |

После вычисления aggregate применяется обычная защита от дребезга: три последовательных aggregate failures открывают transport incident, два aggregate successes подтверждают recovery.

## Cadence

У каждого remote job сохраняется interval endpoint. Runner просыпается с минимальным из host-metrics interval и probe intervals, но host metrics собирает только когда наступил их собственный срок. Поэтому 10-секундная проверка сайта не превращает 60-секундный сбор CPU/RAM в 10-секундный.

## История и отчёт надёжности

Raw central и remote observations хранятся раздельно. Графики TTFB/response/assertions остаются центральными, но отчёт **Аналитика → Надёжность** для сайтов пересчитывает transport каждой центральной автоматической проверки с учётом свежих remote observations и настроенного failure quorum. Missing/stale remote observation не считается failure. Assertions по-прежнему берутся только из Central MirvMon.

В деталях сайта на вкладке **События** показывается компактная история переходов доступности каждой точки за последние 7 дней плюс её последнее состояние. Это позволяет увидеть, какая именно точка перестала или снова начала видеть endpoint.

Endpoint с authentication, custom headers или `allow_self_signed` агенту не выдаётся. Для такого endpoint effective quorum автоматически становится `1`, даже если на уровне сайта выбран больший quorum: Central MirvMon остаётся единственной авторитетной transport-точкой, поэтому центральный отказ не может быть скрыт отсутствующими remote results.
