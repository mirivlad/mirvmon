# Troubleshooting MirvMon

Практическая последовательность проверки, когда MirvMon или агент перестали
показывать свежие данные.

## 1. Сначала разделите «сервер MirvMon» и «агент»

На хосте MirvMon:

```bash
curl --fail http://127.0.0.1:8080/livez
curl --fail http://127.0.0.1:8080/readyz
```

- `/livez` проверяет HTTP runtime;
- `/readyz` проверяет приложение и БД.

Если оба endpoint отвечают, но один агент не доставляет метрики, начинайте с
агента, а не с БД/UI.

## 2. Проверка Linux-агента

```bash
sudo systemctl status mirvmon-agent --no-pager -l
sudo journalctl -u mirvmon-agent -n 100 --no-pager
sudo /opt/mirvmon-agent/mirvmon-agent version
```

Затем выполните server check:

```bash
sudo -u mirvmon-agent \
  /opt/mirvmon-agent/mirvmon-agent check \
  --config /etc/mirvmon-agent/config.json \
  --server
echo "check rc=$?"
```

И один рабочий цикл:

```bash
sudo -u mirvmon-agent \
  /opt/mirvmon-agent/mirvmon-agent once \
  --config /etc/mirvmon-agent/config.json \
  --require-delivery
echo "once rc=$?"
```

После этого посмотрите состояние и очередь:

```bash
sudo cat /var/lib/mirvmon-agent/health.json
sudo ls -lh /var/lib/mirvmon-agent/queue.json
```

Подробное описание команд и кодов возврата: [agent.md](agent.md).

## 3. Интерпретация типичных состояний

### `authentication_error`

Проверьте, что сервер не отключён в MirvMon и agent token не был отозван или
заменён. Не копируйте token в диагностику.

### `dns_error` или `network_timeout`

Сначала проверьте resolver:

```bash
getent ahostsv4 monitoring.example.com
time getent ahostsv4 monitoring.example.com
cat /etc/resolv.conf
```

Если установлен `dig`, проверьте каждый configured nameserver напрямую:

```bash
for dns in $(awk '/^nameserver/{print $2}' /etc/resolv.conf); do
    echo "--- $dns ---"
    dig @"$dns" monitoring.example.com A +time=2 +tries=1
 done
```

Полезно отдельно сравнить UDP и TCP DNS:

```bash
dig @1.1.1.1 monitoring.example.com A +time=2 +tries=1
dig @1.1.1.1 monitoring.example.com A +tcp +time=2 +tries=1
```

Если TCP отвечает, а обычный запрос timeout'ится, проблема может быть именно в
UDP/53, а не в маршруте до DNS-сервера.

Чтобы проверить HTTPS без системного DNS, временно подставьте известный IP:

```bash
curl -4 -v \
  --resolve monitoring.example.com:443:203.0.113.10 \
  --connect-timeout 5 \
  --max-time 20 \
  https://monitoring.example.com/livez \
  -o /dev/null
```

Замените `203.0.113.10` на фактический адрес вашей установки. Успешный запрос
через `--resolve` при обычном DNS timeout почти полностью локализует проблему в
resolver path.

### `tls_error`

Проверьте время на хосте, hostname/SAN сертификата и системный CA store:

```bash
date -u
curl -v https://monitoring.example.com/livez -o /dev/null
```

Не лечите production TLS отключением проверки сертификата.

### `server_error`

Проверьте HTTP status и server logs. Без token config endpoint обычно должен
быстро вернуть 401/403, а не зависать:

```bash
curl -v --max-time 20 \
  https://monitoring.example.com/api/v1/agent/config \
  -o /dev/null
```

На хосте MirvMon:

```bash
docker compose -f docker/docker-compose.yml logs --tail=200 app
```

### Очередь растёт

Рост `queue.json` означает, что агент собирает замеры быстрее, чем может их
доставить. Сначала исправьте transport/auth/server error; после восстановления
связи агент будет досылать старейшие элементы очереди.

Не удаляйте очередь просто для того, чтобы убрать симптом: это уничтожает
недоставленные замеры.

## 4. Proxy

Проверьте окружение service process:

```bash
sudo systemctl show mirvmon-agent -p Environment
sudo tr '\0' '\n' < /proc/$(pidof mirvmon-agent)/environ | grep -i proxy || true
```

Агент учитывает `HTTP_PROXY`, `HTTPS_PROXY` и `NO_PROXY`. Ошибочный proxy может
выглядеть как сетевой timeout или server error.

## 5. Проверка от имени service user

Root shell и service user могут иметь разные permissions/environment. Проверяйте
transport также от имени агента:

```bash
sudo -u mirvmon-agent curl -4 -v \
  --connect-timeout 5 \
  --max-time 20 \
  https://monitoring.example.com/livez \
  -o /dev/null
```

## 6. Агент работает, `once` успешен, но UI устарел

Если `check --server` и `once --require-delivery` дают `0`, а
`last_delivery_at` обновляется, проблема уже после агента. Проверьте:

- server/application logs на `POST /api/v1/metrics`;
- время последнего authenticated contact и sample time на странице сервера;
- clock skew наблюдаемого хоста;
- состояние БД и `/readyz`;
- фильтры/группу/выбранный период в UI.

## 7. Self-update Linux

```bash
sudo systemctl status mirvmon-agent-update.path mirvmon-agent-update.service
sudo journalctl -u mirvmon-agent-update.service -n 100 --no-pager
sudo cat /var/lib/mirvmon-agent/update-state.json
```

### Однократное восстановление старого Linux-агента v0.4.5

У `v0.4.5` локальный update state мог остаться в `awaiting_restart` уже после
успешной установки и блокировать следующую команду. Применяйте этот recovery
только если бинарник действительно сообщает `v0.4.5`, а
`update-state.json` содержит target `v0.4.5` и state `awaiting_restart`.

```bash
sudo systemctl stop mirvmon-agent-update.path mirvmon-agent-update.service mirvmon-agent.service
sudo sh -c 'recovery_dir="/var/lib/mirvmon-agent/recovery-v045-$(date -u +%Y%m%dT%H%M%SZ)"; install -d -m 0700 "$recovery_dir"; for file in update-state.json update-request.json update-handoff update-staged; do if [ -e "/var/lib/mirvmon-agent/$file" ]; then mv "/var/lib/mirvmon-agent/$file" "$recovery_dir/"; fi; done; printf "Backup: %s\n" "$recovery_dir"'
sudo systemctl start mirvmon-agent-update.path mirvmon-agent.service
```

Permanent token, config и metrics queue эта процедура не меняет.

## 8. Что приложить к bug report

Без секретов:

```bash
/opt/mirvmon-agent/mirvmon-agent version
systemctl status mirvmon-agent --no-pager -l
journalctl -u mirvmon-agent -n 100 --no-pager
cat /var/lib/mirvmon-agent/health.json
ls -lh /var/lib/mirvmon-agent/queue.json
```

Конфигурацию прикладывайте только с удалённым `token`.
