# Установка и запуск системы мониторинга

## Требования

- PHP 8.3 или выше
- Composer
- MySQL 8+ или MariaDB 10.5+
- Apache или Nginx

## Установка

### 1. Клонирование проекта

```bash
git clone <repository-url>
cd mon
```

### 2. Установка зависимостей

```bash
composer install
```

### 3. Настройка базы данных

1. Создайте базу данных:

```sql
CREATE DATABASE monitoring_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Импортируйте схему:

```bash
mysql -u root -p monitoring_system < schema.sql
```

### 4. Настройка веб-сервера

#### Apache

Создайте виртуальный хост:

```apache
<VirtualHost *:80>
    ServerName mon.mirv.top
    DocumentRoot /path/to/monitoring-system/public
    
    <Directory /path/to/monitoring-system/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/mon_error.log
    CustomLog ${APACHE_LOG_DIR}/mon_access.log combined
</VirtualHost>
```

#### Nginx

```nginx
server {
    listen 80;
    server_name mon.mirv.top;
    root /path/to/monitoring-system/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

### 5. Настройка окружения

Скопируйте `.env.example` в `.env` и укажите параметры подключения к базе данных:

```bash
cp .env.example .env
```

Ключевые переменные:

```dotenv
DB_HOST=localhost
DB_NAME=monitoring_system
DB_USERNAME=mon_user
DB_PASSWORD=your_db_password
APP_PORT=8082
```

## Использование

### 1. Вход в систему

Перейдите на `http://mon.mirv.top/login` и войдите в систему.

По умолчанию миграция `007_seed_admin_user.sql` создаёт пользователя:

- логин: `admin`
- пароль: `mirvmon2026`

### 2. Добавление серверов

1. Перейдите в раздел "Серверы"
2. Нажмите "Добавить сервер"
3. Заполните форму и сохраните
4. На странице подтверждения скопируйте токен и скачайте скрипт установки

### 3. Установка агента на мониторимый сервер

1. Скачайте скрипт установки с сервера мониторинга
2. Загрузите его на сервер, который нужно мониторить
3. Выполните:

```bash
chmod +x install.sh
./install.sh
```

Агент будет установлен как systemd-сервис и начнет отправлять метрики на сервер мониторинга.

## Trends и длинные периоды

Для графиков за периоды больше 24 часов используются агрегированные данные из `server_metrics_trends`.
Если после установки или миграции нужно дозаполнить историю:

```bash
php /var/www/mon/cron/backfill_trends.php 30
```

## API

### Отправка метрик

Агенты отправляют метрики на эндпоинт `/api/v1/metrics` методом POST:

```json
{
    "token": "токен_сервера",
    "metrics": {
        "cpu_load": 45.2,
        "ram_used": 89.1,
        "ram_total_gb": 16.0,
        "disk_used_root": 65.5,
        "net_in_ens3": 12.3,
        "net_out_ens3": 5.6
    },
    "services": [
        {"name": "nginx", "status": "running"}
    ]
}
```

### Скачивание агента

```
GET /get-agent?token=<server_token>
GET /agent/install.sh?token=<server_token>
GET /agent/install.bat?token=<server_token>
```

## Безопасность

- Все пароли хешируются с помощью `password_hash()`
- Токены агентов хранятся в виде SHA-256 хешей
- Все SQL-запросы используют подготовленные выражения
- Вход на все страницы требует аутентификации (кроме API и страницы входа)
