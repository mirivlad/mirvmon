# mirvmon — Система мониторинга серверов

> Легкая и функциональная система мониторинга для домашних и небольших серверных инфраструктур.

[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4.svg)](https://php.net) [![Slim](https://img.shields.io/badge/Slim-4.x-3399FF.svg)](https://www.slimframework.com) [![MariaDB](https://img.shields.io/badge/MariaDB-10.11-003545.svg)](https://mariadb.org) [![Chart.js](https://img.shields.io/badge/Chart.js-4.x-FF6384.svg)](https://www.chartjs.org)

---

## Возможности

- **Метрики в реальном времени:** CPU, RAM, диск (по разделам), сеть (In/Out в %)
- **Алерты и уведомления:** Настраиваемые пороги с уведомлениями через Telegram и Email
- **Мониторинг сервисов:** Отслеживание состояния systemd-сервисов с алертами при остановке
- **Интерактивные графики:** Зум, панорамирование, детализация по периодам (1ч, 6ч, 24ч, 7д, 30д)
- **Множество серверов:** Поддержка неограниченного числа серверов через Python-агент
- **Цветная индикация:** Прогресс-бары меняют цвет при приближении к порогам
- **Авторизация:** Сессионная аутентификация с CSRF-защитой

---

## Установка

### Вариант A: Docker (рекомендуется)

Самый быстрый способ - все поднимается автоматически:

```bash
# 1. Клонируем
git clone https://git.mirv.top/mirivlad/mirvmon.git
cd mirvmon

# 2. Запускаем скрипт (сам поставит Docker, сгенерит пароли, поднимет)
cd docker && bash deploy.sh

# 3. Открываем браузер
# http://localhost:8080
# Логин: admin
# Пароль: mirvmon2026 (смените сразу!)
```

**Ручной Docker (без скрипта):**

```bash
git clone https://git.mirv.top/mirivlad/mirvmon.git
cd mirvmon

cp .env.example .env        # меняем пароли
docker compose -f docker/docker-compose.yml up -d --build
```

**При обновлении:**

```bash
git pull
docker compose -f docker/docker-compose.yml up -d --build
```

> Код внутри Docker-образа (immutable). Данные БД в volume `db_data` - не теряются.

---

### Вариант B: Ручная установка

#### 1. Зависимости

```bash
apt install php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-curl nginx mariadb-server
composer install
```

#### 2. База данных

```bash
mysql -u root -p <<EOF
CREATE DATABASE monitoring_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mon_user'@'localhost' IDENTIFIED BY 'mon_password_123';
GRANT ALL PRIVILEGES ON monitoring_system.* TO 'mon_user'@'localhost';
FLUSH PRIVILEGES;
EOF

# Загружаем миграции
for f in docker/migrations/*.sql; do
    mysql -u mon_user -pmon_password_123 monitoring_system < "$f"
done
```

#### 3. Настройка .env

```bash
cp .env.example .env
# Отредактируйте DB_HOST, DB_PASSWORD и другие параметры
nano .env
```

#### 4. Nginx

```nginx
server {
    listen 80;
    server_name mon.mirv.top;
    root /var/www/mon/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

#### 5. Вход в систему

`http://your-server/login`

- Логин: `admin`
- Пароль: `mirvmon2026` (смените сразу!)

---

## Архитектура

```
+-----------+         POST /api/v1/metrics         +----------+
|  Python   |  ------------------------------->     |   PHP    |
|  Agent    |   JSON: metrics + services         | Backend  |
|  (server) |  <-------------------------------   +----+-----+
+-----------+                                       ^      |
    ^                                               |      | PDO
    | systemctl list-units                           |      v
+-----------+                                 +-----------+
|  Linux    |                                 |  MariaDB  |
|  OS       |                                 |           |
+-----------+                                 +-----------+
```

### Технологический стек

| Компонент   | Технология              |
|-------------|-------------------------|
| Backend     | Slim Framework 4.x      |
| Frontend    | Bootstrap 5 + Chart.js  |
| Database    | MariaDB 10.11           |
| Agent       | Python 3 + psutil       |

---

## Установка агента на сервер

Агент устанавливается через скачивание скрипта с сервера мониторинга:

```bash
# 1. В веб-интерфейсе: Серверы -> Редактирование -> Скачать установочный скрипт

# 2. На целевом сервере:
chmod +x install.sh
sudo ./install.sh
```

Агент будет:
- Установлен в `/opt/server-monitor-agent/`
- Запущен как systemd-сервис
- Отправлять метрики каждые 60 секунд

---

## API

### Получение метрик от агента

```
POST /api/v1/metrics
Content-Type: application/json

{
    "token": "server_token",
    "metrics": {
        "cpu_load": 45.2,
        "ram_used": 89.1,
        "disk_used_root": 65.5,
        "net_in_ens3": 12.3,
        "net_out_ens3": 5.6
    },
    "services": [
        {"name": "nginx", "status": "running"},
        {"name": "mysql", "status": "stopped"}
    ]
}
```

### Другие endpoints

| Endpoint                        | Описание                           |
|---------------------------------|------------------------------------|
| `GET /`                         | Дашборд                            |
| `GET /servers`                   | Управление серверами                |
| `GET /servers/{id}`              | Детали сервера с графиками         |
| `GET /agent/install.sh?token=`  | Скачать установочный скрипт (Linux)|
| `GET /agent/install.bat?token=` | Скачать установочный скрипт (Win)  |
| `POST /api/v1/metrics`          | Получение метрик от агента         |
| `GET /alerts`                    | Список алертов                     |
| `POST /alerts/{id}/resolve`     | Отметить алерт решенным            |

---

## Troubleshooting

### Агент не отправляет метрики

```bash
# Проверить статус сервиса
sudo systemctl status server-monitor-agent

# Посмотреть логи
sudo journalctl -u server-monitor-agent -f

# Перезапустить
sudo systemctl restart server-monitor-agent
```

### Агент не устанавливается

```bash
# Проверить Python
python3 --version

# Установить зависимости вручную
sudo apt install python3-pip
sudo pip3 install psutil requests
```

### Docker: контейнер не стартует

```bash
# Проверить логи
docker compose logs app

# Пересобрать
docker compose down && docker compose up -d --build
```

---

## Документация

- [ARCHITECTURE.md](ARCHITECTURE.md) - Архитектура системы
- [TECHNICAL_SPECIFICATION.md](TECHNICAL_SPECIFICATION.md) - Техническое задание
- [INSTALL.md](INSTALL.md) - Детальная инструкция установки
- [docker/README.md](docker/README.md) - Docker-развертывание

---

## Changelog

### v1.1 (Апрель 2026)
- Динамические пороги для метрик сервера
- Улучшенный агент с поддержкой VPS (virtio-диски)
- Скачивание агента с сервера (вместо встраивания в скрипт)
- Защита endpoint агента токеном
- Doughnut-графики для разделов дисков
- Графики температур и сетевых интерфейсов
- Топ-процессы в тултипах графиков
- Мониторинг systemd-сервисов

### v1.0 (Февраль 2026)
- Ядро системы (Slim 4 + Twig + Bootstrap 5)
- Аутентификация и авторизация
- CRUD серверов и групп
- API для приема метрик
- Python-агент с systemd
- Графики Chart.js
- Система алертов с порогами
- Топ-5 процессов по CPU/RAM
- Уведомления (Email/Telegram)

---

## Лицензия

MIT
