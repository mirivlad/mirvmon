# 🖥️ mirvmon — Система мониторинга серверов

> Лёгкая и функциональная система мониторинга для домашних и небольших серверных инфраструктур.

[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4.svg)](https://php.net)
[![Slim](https://img.shields.io/badge/Slim-4.x-3399FF.svg)](https://www.slimframework.com)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.11-003545.svg)](https://mariadb.org)
[![Chart.js](https://img.shields.io/badge/Chart.js-4.x-FF6384.svg)](https://www.chartjs.org)

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

---

## 🚀 Быстрый старт

### 1. Клонирование

```bash
git clone https://git.mirv.top/mirivlad/mirvmon.git
cd mirvmon
```

### 2. Установка зависимостей

```bash
composer install
```

### 3. База данных

```bash
mysql -u root -p monitoring_system < monitoring_system_dump.sql
```

### 4. Настройка БД

Отредактируйте `config/DatabaseConfig.php`:
```php
private $host = 'localhost';
private $db_name = 'monitoring_system';
private $username = 'mon_user';
private $password = 'mon_password_123';
```

### 5. Веб-сервер (Nginx)

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

### 6. Вход в систему

Перейдите на `http://mon.mirv.top/login`. Пароль по умолчанию для тестовых данных: `admin123`.

---

## 🤖 Установка Python-агента

### На мониторимом сервере:

1. Создайте сервер через веб-интерфейс и получите **токен**.
2. Создайте файл `/opt/server-monitor-agent/config.json`:

```json
{
    "token": "ваш_токен_с_веб-страницы",
    "api_url": "https://mon.mirv.top/api/v1/metrics",
    "interval_seconds": 60
}
```

3. Установите зависимости:
```bash
pip install psutil requests
```

4. Скопируйте `agent.py` в `/opt/server-monitor-agent/` и настройте systemd-сервис:

```ini
[Unit]
Description=Server Monitor Agent
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/server-monitor-agent
ExecStart=/usr/bin/python3 /opt/server-monitor-agent/agent.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

5. Запустите: `systemctl enable --now server-monitor-agent`

### Что собирает агент:
- **CPU** (`cpu_load`) — загрузка процессора (%)
- **RAM** (`ram_used`) — использование памяти (%) + топ-5 процессов
- **Диск** (`disk_used_root`, `disk_used_home`, ...) — по каждому реальному разделу (%) + общий объём (GB)
- **Сеть** (`net_in_enp4s0`, `net_out_enp4s0`) — % использования пропускной способности интерфейса
- **Сервисы** — статус всех systemd-юнитов (running/stopped)

---

## 📁 Структура проекта

```
/var/www/mon/
├── config/
│   └── DatabaseConfig.php          # Настройки подключения к БД
├── public/
│   ├── index.php                   # Точка входа (Slim Framework)
│   ├── chartjs-plugin-zoom.min.js  # Плагин зума для графиков
│   └── hammer.min.js               # Зависимость zoom (CDN)
├── src/
│   ├── Controllers/
│   │   ├── DashboardController.php     # Дашборд с карточками серверов
│   │   ├── ServerDetailController.php  # Детали сервера + графики + пороги
│   │   ├── ServerController.php        # CRUD серверов
│   │   ├── GroupController.php         # CRUD групп
│   │   ├── AlertController.php         # Управление алертами
│   │   ├── AdminController.php         # Админ-панель
│   │   └── Api/
│   │       └── MetricsController.php   # REST API для приёма метрик
│   ├── Models/
│   │   ├── Server.php              # Модель сервера
│   │   ├── Group.php               # Модель группы
│   │   └── Alert.php               # Модель алерта
│   ├── Middlewares/
│   │   ├── AuthMiddleware.php      # Проверка авторизации
│   │   ├── CsrfMiddleware.php      # CSRF-защита
│   │   ├── SessionMiddleware.php   # Работа с сессиями
│   │   └── FlashMiddleware.php     # Flash-сообщения
│   └── Services/
│       └── NotificationService.php # Отправка уведомлений (Telegram/Email)
├── templates/
│   ├── layout.twig                 # Базовый layout (Bootstrap 5)
│   ├── dashboard.twig              # Дашборд с карточками
│   ├── servers/detail.twig         # Детали сервера: графики, пороги, сервисы
│   └── ...
├── composer.json
├── monitoring_system_dump.sql      # Дамп структуры + примеры данных
└── schema.sql                      # Чистая схема БД
```

---

## 📊 Метрики и графики

### На странице сервера доступны:

| Метрика | Тип графика | Описание |
|---------|-------------|----------|
| **CPU Загрузка** | Line (синий) | Загрузка процессора во времени |
| **RAM Использование** | Line (фиолетовый) | Использование памяти + топ процессов в тултипе |
| **Сеть: enp4s0** | Line (2 линии: 🟢 In / 🔴 Out) | % использования пропускной способности |
| **Диски** | Doughnut (карточки по 3 в ряд) | Текущее заполнение каждого раздела с GB |

### Управление графиками:
- 🖱️ **Колёсико мыши** — зум
- ✋ **Перетаскивание** — панорамирование
- 🔍 **Кнопка «Сброс»** — возврат к исходному масштабу
- ⏱️ **Период:** 1ч / 6ч / 24ч / 7д / 30д

---

## 🔔 Алерты и уведомления

### Пороги метрик
Для каждой метрики настраиваются:
- ⚠️ **Предупреждение** (warning threshold)
- 🚨 **Критический** (critical threshold)
- ⏱️ **Длительность** (минуты до срабатывания)

### Алерты сервисов
- 🛑 **Остановка** — красное уведомление, создаётся алерт
- ✅ **Запуск** — зелёное уведомление, алерт закрывается
- Отслеживаются только сервисы, отмеченные галочкой во вкладке «Сервисы»

### Каналы уведомлений
- **Telegram** — через Bot API (с поддержкой прокси)
- **Email** — через SMTP или функцию `mail()`

---

## 🗄️ База данных

### Основные таблицы:
| Таблица | Описание |
|---------|----------|
| `servers` | Мониторимые серверы |
| `server_metrics` | Временные ряды метрик |
| `metric_names` | Справочник метрик |
| `metric_thresholds` | Пороги алертов для серверов |
| `alerts` | Активные и закрытые алерты |
| `service_status` | Текущие статусы сервисов |
| `service_alerts` | Алерты на остановку сервисов |
| `agent_configs` | Настройки агентов (интервал, список сервисов) |
| `agent_tokens` | Токены авторизации агентов |
| `server_groups` | Группы серверов |
| `users` | Пользователи системы |
| `global_notification_settings` | Глобальные настройки уведомлений |

---

## ⚙️ Конфигурация

### PHP-FPM
- PHP 8.3+
- Расширения: `pdo`, `pdo_mysql`, `json`, `curl`, `mbstring`

### Nginx
- PHP-FPM через Unix socket
- `try_files` для Slim routing

### Python Agent
- Python 3.8+
- `psutil >= 5.0`
- `requests >= 2.25`
- `systemd` (для сбора статусов сервисов)

---

## 🔒 Безопасность

- ✅ Все пароли хешируются через `password_hash()` (bcrypt)
- ✅ Токены агентов хранятся как SHA-256 хеши
- ✅ Подготовленные выражения (PDO) для всех SQL-запросов
- ✅ CSRF-токены для всех форм
- ✅ Сессионная аутентификация для веб-интерфейса
- ✅ Валидация и санитизация входных данных

---

## 📝 Лицензия

MIT

---

## 👤 Автор

Владимир (mirivlad) — [git.mirv.top](https://git.mirv.top/mirivlad/mirvmon)
