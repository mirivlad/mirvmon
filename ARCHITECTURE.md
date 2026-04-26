# ARCHITECTURE.md — Система мониторинга серверов (mirvmon)

**Домен:** `mon.mirv.top`  
**Версия:** 1.0  
**Дата:** Февраль 2026  
**Статус:** Production

---

## 📋 Обзор

Система мониторинга серверов на базе **Slim Framework 4** с веб-интерфейсом для управления серверами, сбора метрик, визуализации данных и системы алертов.

### Ключевые возможности
- Веб-интерфейс на русском языке (Bootstrap 5 + Chart.js)
- REST API для приёма метрик от агентов
- Python-агент для сбора CPU/RAM/Disk метрик
- Система алертов с настраиваемыми порогами
- Уведомления (Email/Telegram)
- Ролевая модель (admin/user)

---

## 🏗️ Архитектура

### Общая схема
```
+---------------+      POST /api/v1/metrics      +--------------+
|  Python Agent |  -------------------------->   |  PHP Backend |
|  (каждые 60с) |  JSON: metrics + services     |  (Slim 4)    |
+---------------+  <--------------------------   +------+-------+
                                                               ^
+---------------+      +-----------------------+              |
|  Web Browser  | ---> |  /servers/{id}        | ---------------+
|  (Dashboard)  |      |  /alerts, /admin     |
+---------------+      +-----------------------+
```

### Технологический стек
| Компонент | Технология | Версия |
|-----------|------------|--------|
| Backend | Slim Framework | 4.x |
| Templating | Twig | 3.x |
| Frontend | Bootstrap | 5.x |
| Графики | Chart.js | 4.x |
| Icons | Font Awesome | 6.x |
| Database | MySQL/MariaDB | 8+ / 10.5+ |
| Agent | Python + psutil | 3.x |

---

## 📁 Структура проекта

```
/var/www/mon/
├── public/                     # Точка входа (nginx root)
│   └── index.php              # Bootstrap приложения
├── src/
│   ├── Controllers/           # Контроллеры (MVC)
│   │   ├── DashboardController.php
│   │   ├── ServerController.php
│   │   ├── ServerDetailController.php
│   │   ├── GroupController.php
│   │   ├── AlertController.php
│   │   ├── AdminController.php
│   │   ├── AgentController.php
│   │   └── Api/
│   │       └── MetricsController.php
│   ├── Models/                # Модели данных
│   │   ├── Model.php          # Базовый класс
│   │   ├── User.php
│   │   ├── Server.php
│   │   ├── Group.php
│   │   └── Alert.php
│   ├── Middlewares/           # Middleware
│   │   ├── SessionMiddleware.php
│   │   ├── AuthMiddleware.php
│   │   └── CsrfMiddleware.php
│   └── Utils/                 # Утилиты
│       └── EncryptionHelper.php
├── templates/                 # Twig шаблоны
│   ├── layout.twig            # Основной layout
│   ├── login.twig
│   ├── dashboard.twig
│   ├── servers/               # Шаблоны серверов
│   ├── groups/                # Шаблоны групп
│   ├── alerts/                # Шаблоны алертов
│   └── admin/                 # Админка
├── config/                    # Конфигурация
├── agent.py                   # Python-агент
├── composer.json              # PHP зависимости
├── schema.sql                 # Схема БД
└── TECHNICAL_SPECIFICATION.md # ТЗ
```

---

## 🗄️ База данных

### Схема
```
users                          # Пользователи (admin/user)
├── id, username (unique), password_hash, email, role

user_notification_settings     # Настройки уведомлений
├── id, user_id, telegram_chat_id, email_for_alerts

server_groups                  # Группы серверов
├── id, name, description, icon, color

servers                        # Серверы
├── id, name, address, group_id, description, last_metrics_at

metric_names                   # Справочник метрик
├── id, name (unique), unit, description

metric_thresholds              # Пороги для серверов
├── id, server_id, metric_name_id, warning_threshold, critical_threshold, duration

server_metrics                 # История метрик
├── id, server_id, metric_name_id, value, created_at

agent_tokens                   # Токены агентов (хеши)
├── id, server_id (unique), token_hash (SHA-256), created_at, last_used_at

alerts                         # Алерты
├── id, server_id, metric_name, value, severity, resolved, created_at, resolved_at
```

### Индексы
- `server_metrics(server_id, metric_name_id, created_at)` — для быстрого поиска по периоду
- `servers(last_metrics_at)` — для фильтрации статусов
- `alerts(resolved, created_at)` — для списка активных алертов

---

## 🔐 Безопасность

### Аутентификация
- Сессионная авторизация (`$_SESSION`)
- Хеширование паролей: `password_hash()` (bcrypt)
- Middleware `AuthMiddleware` защищает все маршруты кроме `/login`, `/api/v1/metrics`

### CSRF защита
- Slim CSRF Guard с персистентными токенами
- AJAX endpoint `/csrf-token` для получения токенов
- Формы включают CSRF токены

### Токены агентов
- Хранятся только как **SHA-256 хеши** в `agent_tokens`
- Исходный токен показывается пользователю **однократно** при создании сервера
- API `/api/v1/metrics` проверяет хеш, не требует сессии

### SQL инъекции
- Prepared statements (PDO) во всех запросах
- Базовый класс `Model` даёт единый доступ к PDO через `DatabaseConfig`

---

## 🌐 Маршруты (Routes)

### Публичные (без авторизации)
| Метод | Путь | Контроллер | Описание |
|-------|------|------------|----------|
| GET | `/login` | — | Форма входа |
| POST | `/login` | — | Аутентификация |
| GET | `/logout` | — | Выход |
| POST | `/api/v1/metrics` | `MetricsController` | Приём метрик от агентов |
| GET | `/csrf-token` | — | Получить CSRF токен |

### Защищённые (требуется вход)
| Метод | Путь | Контроллер | Описание |
|-------|------|------------|----------|
| GET | `/` | `DashboardController` | Дашборд (карточки серверов) |
| GET | `/server/{id}` | redirect | Legacy redirect на `/servers/{id}` |
| GET | `/servers/{id}` | `ServerDetailController` | Детали сервера + графики |
| GET/POST | `/servers/create` | `ServerController` | Добавить сервер |
| GET/POST | `/servers/{id}/edit` | `ServerController` | Редактировать сервер |
| GET/POST | `/servers/{id}/delete` | `ServerController` | Удалить сервер |
| GET/POST | `/groups` | `GroupController` | Управление группами |
| GET | `/alerts` | `AlertController` | Список алертов |
| POST | `/alerts/{id}/resolve` | `AlertController` | Пометить алерт исправленным |
| GET | `/admin/users` | `AdminController` | Пользователи (admin only) |
| GET | `/admin/notifications` | `AdminController` | Настройки уведомлений |

---

## 🤖 Агент мониторинга

### agent.py
**Расположение:** `/var/www/mon/agent.py`

**Собираемые метрики:**
- `cpu_load` - загрузка CPU (%)
- `ram_used` - использовано RAM (%)
- `ram_total_gb` - всего RAM (ГБ)
- `disk_used_*` - использовано по разделам (%)
- `disk_total_gb_*` - размер разделов (ГБ)
- `net_in_{iface}`, `net_out_{iface}` - трафик (% от скорости интерфейса)
- `temp_*` - температуры (°C)
- `top_cpu_proc`, `top_ram_proc` - топ-5 процессов (JSON)
- `uptime` - аптайм в секундах

**Интервал сбора:** 60 секунд

### install.sh
**Endpoint:** `GET /agent/install.sh?token={token}`

**Что делает:**
1. Проверяет Python 3, устанавливает psutil, lm-sensors, smartmontools
2. Скачивает `agent.py` с сервера мониторинга
3. Создает конфиг с токеном и URL API
4. Создает systemd сервис `server-monitor-agent.service`

**Безопасность:** Скрипт и агент доступны только с валидным токеном сервера.

---

## 📊 Логика статусов серверов

### Цвета карточек на дашборде
| Цвет | Условие |
|------|---------|
| 🟢 **Зелёный** | `last_metrics_at` < 2 мин И нет активных алертов |
| 🟡 **Жёлтый** | Метрики свежие, но есть алерты (warning/critical) |
| 🔴 **Красный** | `last_metrics_at` > 5 мин (сервер не отвечает) |

### Проверка порогов
1. Агент отправляет метрику → `server_metrics`
2. Система ищет порог в `metric_thresholds` для этого сервера+метрики
3. Если `value > warning_threshold` → создаётся запись в `alerts`
4. Статус сервера обновляется на дашборде

---

## 🎨 Frontend

### Шаблоны Twig
- `layout.twig` — основной layout (меню, футер, подключение CSS/JS)
- `login-layout.twig` — упрощённый layout для страницы входа
- `dashboard.twig` — карточки серверов с автообновлением (30с)
- `servers/*.twig` — формы CRUD серверов
- `alerts/*.twig` — список алертов

### JavaScript
- Chart.js для графиков CPU/RAM/Network/Temperature/Disk
- Автообновление дашборда через `setTimeout`
- Внешний tooltip handler для загрузки данных процессов и деталей RAM

---

## 🚀 Развёртывание

### Требования
- PHP 8.1+ с расширениями: pdo, pdo_mysql, mbstring
- Composer
- MySQL 8+ / MariaDB 10.5+
- nginx + PHP-FPM
- Python 3 + pip (для агента)

### Установка
```bash
# 1. Клонировать репозиторий
cd /var/www
git clone <repo> mon
cd mon

# 2. Установить зависимости
composer install --no-dev

# 3. Создать БД
mysql -u root -p < schema.sql

# 4. Настроить nginx (см. /etc/nginx/sites-enabled/mon.mirv.top)

# 5. Настроить PHP-FPM
# /run/php/php8.3-fpm.sock

# 6. Получить SSL сертификат
certbot --nginx -d mon.mirv.top

# 7. Создать админа
# Через phpMyAdmin или напрямую в MySQL
```

### Конфигурация nginx
- **Root:** `/var/www/mon/public`
- **PHP-FPM:** `unix:/run/php/php8.3-fpm.sock`
- **SSL:** Let's Encrypt
- **HTTP → HTTPS редирект**

---

## 📈 Метрики

### Стандартные метрики (metric_names)
| Имя | Единица | Описание |
|-----|---------|---------|
| `cpu_load` | % | Загрузка CPU |
| `ram_used` | % | Использовано RAM |
| `ram_total_gb` | ГБ | Всего RAM |
| `disk_used` | % | Использовано (общий) |
| `disk_used_root` | % | Использовано (/) |
| `disk_used_home` | % | Использовано (/home) |
| `disk_used_*` | % | Другие разделы |
| `net_in_{iface}` | % | Входящий трафик |
| `net_out_{iface}` | % | Исходящий трафик |
| `temp_cpu` | °C | Температура CPU |
| `temp_hdd` | °C | Температура диска |
| `top_cpu_proc` | JSON | Топ-5 процессов по CPU |
| `top_ram_proc` | JSON | Топ-5 процессов по RAM |

### Период сбора
- **Агент:** каждые 60 секунд
- **Дашборд:** автообновление каждые 30 секунд
- **Графики:** 1 час / 6 часов / 24 часа / 7 дней / 30 дней
- **Длинные периоды:** данные берутся из `server_metrics_trends`

---

## 🔧 Расширение

### Добавление новой метрики
1. Добавить запись в `metric_names`
2. Обновить агент для сбора метрики
3. Добавить отображение в шаблонах
4. Опционально: настроить пороги в `metric_thresholds`

---

## 🐛 Известные проблемы

### Исправленные
- ✅ CSRF token generation order (generateToken до getToken)
- ✅ PDO parameter naming (уникальные имена параметров)
- ✅ VARCHAR длина для service_name (255+)
- ✅ API endpoints без CSRF middleware

### Требования к окружению
- PHP 8.3+ (проверено на php8.3-fpm)
- MariaDB 10.6+ (JSON функции для top_cpu_proc/top_ram_proc)

---

## 📝 Changelog

### v1.1 (Апрель 2026)
- ✅ Ядро системы (Slim 4 + Twig + Bootstrap 5)
- ✅ Базовая схема и последующие миграции для trends, сервисов и offline-логики
- ✅ Аутентификация и авторизация
- ✅ CRUD серверов и групп
- ✅ API для приёма метрик
- ✅ Python-агент с systemd
- ✅ Графики Chart.js (24ч/7д/30д)
- ✅ Система алертов с порогами
- ✅ Топ-5 процессов по CPU/RAM
- ✅ Уведомления (Email/Telegram)

---

## 🔗 Ссылки

- **Домен:** https://mon.mirv.top
- **Код:** `/var/www/mon/`
- **База данных:** MySQL (monitoring_system)
- **Агент:** `/var/www/mon/agent.py`
- **Схема:** `/var/www/mon/schema.sql`
- **ТЗ:** `/var/www/mon/TECHNICAL_SPECIFICATION.md`
