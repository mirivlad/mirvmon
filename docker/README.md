# MirvMon — Docker Setup

## Быстрый старт

```bash
# 1. Копируем конфиг
cp .env.example .env

# 2. Меняем пароли в .env
nano .env

# 3. Запускаем
docker compose up -d --build

# 4. Открываем http://localhost:8080
# Логин: admin, Пароль: admin (сменить сразу!)
```

## Обновление

```bash
# Обновить код и пересобрать
git pull
docker compose up -d --build

# Или если образы в registry:
docker compose pull
docker compose up -d
```

## Структура

```
docker/
├── Dockerfile              # PHP 8.3 FPM + приложение
├── docker-compose.yml      # app + nginx + db
├── nginx.conf              # конфиг nginx
├── init.sh                 # entrypoint (ждёт БД + миграции)
├── migrate.sh              # скрипт миграций
├── migrations/             # SQL миграции с версионированием
│   ├── 001_create_base_schema.sql
│   ├── 002_add_encrypted_token.sql
│   ├── ...
├── .env.example            # шаблон конфига
└── README.md               # этот файл
```

## Миграции

Каждая миграция — SQL файл с номером в имени. Система отслеживает применённые в таблице `schema_migrations`.

Добавить новую:
```bash
echo "ALTER TABLE servers ADD COLUMN foo VARCHAR(50);" > docker/migrations/009_add_foo.sql
docker compose up -d --build
```

## Переменные окружения (.env)

| Переменная | Описание | По умолчанию |
|---|---|---|
| `APP_PORT` | Порт веб-интерфейса | `8080` |
| `DB_HOST` | Хост БД | `db` |
| `DB_NAME` | Имя базы | `monitoring_system` |
| `DB_USERNAME` | Пользователь БД | `mon_user` |
| `DB_PASSWORD` | Пароль БД | `mon_password_123` |
| `DB_ROOT_PASSWORD` | Root пароль БД | (обязательно сменить!) |

## Тома (persistent data)

| Volume | Что хранит |
|---|---|
| `db_data` | База данных MariaDB |
| `app_var` | Кэш Twig и логи PHP |

Код приложения **не** монтируется — он внутри образа. Обновление = новый образ.
