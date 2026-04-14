#!/bin/bash
# docker/init.sh — Entry point script
# Ждёт БД, запускает миграции, потом стартует PHP-FPM

set -e

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-monitoring_system}"
DB_USER="${DB_USERNAME:-mon_user}"
DB_PASS="${DB_PASSWORD:-mon_password_123}"

# Флаг для отключения SSL (MariaDB в контейнере без SSL)
MYSQL_OPTS="--skip-ssl"

echo "🚀 MirvMon — Starting up..."

# ------------------------------------------
# 1. Ожидание готовности БД
# ------------------------------------------
echo "⏳ Waiting for MariaDB at ${DB_HOST}:${DB_PORT}..."
MAX_RETRIES=30
RETRY=0

while ! mysql $MYSQL_OPTS -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1;" "$DB_NAME" >/dev/null 2>&1; do
    RETRY=$((RETRY + 1))
    if [ $RETRY -ge $MAX_RETRIES ]; then
        echo "❌ Database not ready after $MAX_RETRIES attempts"
        exit 1
    fi
    echo "   Retry $RETRY/$MAX_RETRIES..."
    sleep 2
done
echo "✅ Database is ready"

# ------------------------------------------
# 2. Запуск миграций
# ------------------------------------------
echo "📦 Running database migrations..."

# Экспортием переменные для migrate.sh
export DB_HOST DB_PORT DB_NAME DB_USERNAME DB_PASSWORD

migrate.sh

echo ""

# ------------------------------------------
# 3. Запуск PHP-FPM
# ------------------------------------------
echo "🟢 Starting PHP-FPM..."
exec "$@"
