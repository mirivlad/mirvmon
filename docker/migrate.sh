#!/bin/bash
# docker/migrate.sh — Run pending database migrations
# Использует таблицу migrations для отслеживания применённых миграций

set -e

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-monitoring_system}"
DB_USER="${DB_USERNAME:-mon_user}"
DB_PASS="${DB_PASSWORD:-mon_password_123}"

MIGRATIONS_DIR="/var/www/docker/migrations"

MYSQL_CMD="mysql --skip-ssl -h${DB_HOST} -P${DB_PORT} -u${DB_USER} -p${DB_PASS} ${DB_NAME}"

echo ""
echo "📋 Checking migrations..."

# ------------------------------------------
# 1. Создаём таблицу отслеживания миграций
# ------------------------------------------
$MYSQL_CMD -e "
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
" 2>/dev/null

# ------------------------------------------
# 2. Применяем миграции по порядку
# ------------------------------------------
APPLIED=0
SKIPPED=0

for migration_file in $(ls "$MIGRATIONS_DIR"/*.sql 2>/dev/null | sort); do
    filename=$(basename "$migration_file")
    
    # Проверяем была ли уже применена
    EXISTS=$($MYSQL_CMD -sN -e "SELECT COUNT(*) FROM schema_migrations WHERE filename='${filename}';" 2>/dev/null)
    
    if [ "$EXISTS" = "1" ]; then
        echo "   ⏭️  $filename (already applied)"
        SKIPPED=$((SKIPPED + 1))
        continue
    fi
    
    echo "   ▶️  $filename ..."
    
    # Применяем миграцию
    if $MYSQL_CMD < "$migration_file" 2>&1; then
        # Записываем в tracking
        $MYSQL_CMD -e "INSERT INTO schema_migrations (filename) VALUES ('${filename}');"
        echo "   ✅ $filename applied"
        APPLIED=$((APPLIED + 1))
    else
        echo "   ❌ Failed to apply $filename"
        exit 1
    fi
done

echo ""
echo "📊 Migrations: $APPLIED applied, $SKIPPED skipped"
