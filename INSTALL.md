# Установка и запуск системы мониторинга

## Требования

- PHP 8.1 или выше
- Composer
- MySQL 8+ или MariaDB 10.5+
- Apache или Nginx

## Установка

### 1. Клонирование проекта

```bash
git clone <repository-url>
cd monitoring-system
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
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

### 5. Настройка конфигурации базы данных

Отредактируйте файл `config/DatabaseConfig.php` для указания параметров подключения к базе данных:

```php
private $host = 'localhost';
private $db_name = 'monitoring_system';
private $username = 'your_db_username';
private $password = 'your_db_password';
```

## Использование

### 1. Вход в систему

Перейдите на `http://mon.mirv.top/login` и войдите в систему.

Для первоначальной настройки создайте администратора через SQL:

```sql
INSERT INTO users (username, password_hash, email, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com', 'admin');
```

Пароль: `password`

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

## API

### Отправка метрик

Агенты отправляют метрики на эндпоинт `/api/v1/metrics` методом POST:

```json
{
    "token": "токен_сервера",
    "metrics": {
        "cpu_load": 45.2,
        "ram_used": 89.1,
        "disk_used": 65.5
    }
}
```

## Безопасность

- Все пароли хешируются с помощью `password_hash()`
- Токены агентов хранятся в виде SHA-256 хешей
- Все SQL-запросы используют подготовленные выражения
- Вход на все страницы требует аутентификации (кроме API и страницы входа)