# 🖥️ mirvmon — Система мониторинга серверов

> Лёгкая и функциональная система мониторинга для домашних и небольших серверных
 инфраструктур.

[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4.svg)](https://php.net)
[![Slim](https://img.shields.io/badge/Slim-4.x-3399FF.svg)](https://www.slimfram
ework.com)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.11-003545.svg)](https://mari
adb.org)
[![Chart.js](https://img.shields.io/badge/Chart.js-4.x-FF6384.svg)](https://www.
chartjs.org)

---

## 📋 Возможности

- 📊 **Метрики в реальном времени:** CPU, RAM, диск (по разделам), сеть (In/Out в %)
- 🔔 **Алерты и уведомления:** Настраиваемые пороги с уведомлениями через Telegram и Email
- 🖧 **Мониторинг сервисов:** Отслеживание состояния systemd-сервисов с алертами при остановке
- 📈 **Интерактивные графики:** Зум, панорамирование, детализация по периодам (1ч, 6ч, 24ч, 7д, 30д)
- 🌐 **Множество серверов:** Поддержка неограниченного числа серверов через Python-агент
- 🎨 **Цветная индикация:** Прогресс-бары меняют цвет (🟢 → 🟡 → 🔴) при приближении к порогам
- 🔐 **Авторизация:** Сессионная аутентификация с CSRF-защитой

---

## 🚀 Установка

### Вариант A: Docker (рекомендуется)

Самый быстрый способ — всё поднимается автоматически:

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

> 📦 Код внутри Docker-образа (immutable). Данные БД в volume `db_data` — не теряются.

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

# Загружаем схему
mysql -u mon_user -pmon_password_123 monitoring_system < docker/migrations/001_create_base_schema.sql
mysql -u mon_user -pmon_password_123 monitoring_system < docker/migrations/002_add_encrypted_token.sql
mysql -u mon_user -pmon_password_123 monitoring_system < docker/migrations/003_add_agent_configs.sql
mysql -u mon_user -pmon_password_123 monitoring_system < docker/migrations/004_add_global_notification_settings.sql
mysql -u mon_user -pmon_password_123 monitoring_system < docker/migrations/005_add_service_tables.sql
mysql -u mon_user -pmon_password_123 monitoring_system < docker/migrations/006_server_metrics_value_to_text.sql
mysql -u mon_user -pmon_password_123 monitoring_system < docker/migrations/007_seed_admin_user.sql
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

## 🏗️ Архитектура

```
┌─────────────────────┐      POST /api/v1/metrics      ┌──────────────────┐
│  Python Agent       │ ──────────────────────────────▶ │  PHP Backend     │
│  (мониторимый       │  JSON: метрики + сервисы        │  (Slim + Twig)   │
│   сервер)           │◀────────────────────────────── │                  │
└─────────────────────┘                                └────────┬─────────┘
        ▲                                                       │
        │ systemctl list-units                                  │ PDO
        │ psutil                                                ▼
┌─────────────────────┐                                ┌──────────────────┐
│  ОС Linux           │                                │  MariaDB         │
│  (systemd, net,     │                                │  monitoring_     │
│   disk, ps)         │                                │  system          │
└─────────────────────┘                                └──────────────────┘
```
