# FAQ MirvMon

## Нужен ли агенту белый IP или открытый входящий порт?

Нет. Агент сам инициирует HTTPS-запросы к MirvMon, поэтому может работать за
NAT и firewall без inbound rule для MirvMon.

## MirvMon polling'ом ходит на наблюдаемые серверы?

Нет. Основная модель — push от агента. Это позволяет одинаково мониторить VPS,
локальные машины и серверы за NAT.

## Что будет, если MirvMon временно недоступен?

Агент сохраняет недоставленные замеры в bounded disk queue и повторяет доставку
после восстановления связи. По умолчанию очередь содержит до 1000 элементов.

## Где посмотреть состояние агента?

Linux:

```bash
sudo systemctl status mirvmon-agent
sudo cat /var/lib/mirvmon-agent/health.json
```

Полная справка: [agent.md](agent.md).

## Как быстро проверить связь агента с сервером?

```bash
sudo -u mirvmon-agent \
  /opt/mirvmon-agent/mirvmon-agent check \
  --config /etc/mirvmon-agent/config.json \
  --server
```

Для реальной отправки одного цикла используйте `once --require-delivery`.

## Где лежит token агента?

В защищённом локальном `config.json`. На серверной стороне permanent token не
хранится в открытом виде: хранится его SHA-256. Token не передаётся в URL.

## Можно ли отключить проверку TLS?

Для обычного production HTTPS — нет необходимости и не следует. Небезопасный
режим ограничен loopback HTTP для локальных тестов.

## Поддерживается ли proxy?

Да. Агент использует стандартные `HTTP_PROXY`, `HTTPS_PROXY` и `NO_PROXY`.
Подробности: [agent.md](agent.md#proxy).

## Поддерживаются ли старые Windows?

Поддерживаются Windows 7 SP1/8/8.1 и Server 2008 R2 SP1/2012/2012 R2 отдельной
legacy x64 сборкой. Windows Server 2008 без R2 и 32-bit системы не
поддерживаются.

## Агент обновляется сам?

Capable agent начиная с `v0.4.3` может принять отдельную self-update команду,
выданную администратором в UI. Произвольные shell-команды сервер агенту не
передаёт.

## Можно ли мониторить сам хост, где работает MirvMon?

Да. Для этого используется обычный MirvMon agent на хосте; контейнеру не нужен
Docker socket или привилегированный доступ ради CPU/RAM/disk метрик.

## Чем отличаются `/livez` и `/readyz`?

`/livez` отвечает на вопрос «HTTP runtime жив?», `/readyz` — «приложение готово
работать и видит БД?». Контейнерный healthcheck использует `/readyz`.

## Где искать, если агент `active (running)`, но данных нет?

Начните не с restart, а с:

```bash
mirvmon-agent check --config ... --server
mirvmon-agent once --config ... --require-delivery
cat health.json
```

Затем используйте [Troubleshooting](troubleshooting.md): DNS, TCP, TLS, auth и
server response там разделены по шагам.
