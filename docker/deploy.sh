#!/bin/bash
# deploy.sh — Быстрый разворот MirvMon на чистом сервере
# Использование: cd docker/ && bash deploy.sh
# Все файлы .env лежат в корне проекта (../.env)

set -e

echo "🚀 MirvMon — Deploy to new server"
echo ""

# ------------------------------------------
# 1. Проверяем Docker
# ------------------------------------------
if ! command -v docker &>/dev/null; then
    echo "❌ Docker not installed. Installing..."
    apt update -qq && apt install -y -qq docker.io docker-compose 2>/dev/null
    echo "✅ Docker installed"
fi

DOCKER_COMPOSE_CMD="docker-compose"
if docker compose version &>/dev/null 2>&1; then
    DOCKER_COMPOSE_CMD="docker compose"
fi

echo "✅ Docker: $(docker --version)"
echo "✅ Compose: $($DOCKER_COMPOSE_CMD version 2>/dev/null || echo 'v1')"
echo ""

# ------------------------------------------
# 2. Определяем пути
# ------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$PROJECT_DIR/.env"
ENV_EXAMPLE="$SCRIPT_DIR/.env.example"

# ------------------------------------------
# 3. Создаём .env если нет
# ------------------------------------------
if [ ! -f "$ENV_FILE" ]; then
    echo "📝 Creating .env from template..."
    cp "$ENV_EXAMPLE" "$ENV_FILE"

    # Генерируем случайные пароли
    ROOT_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)
    DB_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)
    ADMIN_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)

    sed -i "s/DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=${ROOT_PASS}/" "$ENV_FILE"
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" "$ENV_FILE"

    echo "🔐 Generated random passwords:"
    echo "   DB root: $ROOT_PASS"
    echo "   DB user: $DB_PASS"
    echo "   Admin web: mirvmon2026 (по умолчанию, смени в настройках)"
    echo ""
    echo "⚠️  Save these! Change .env if you want custom passwords."
else
    echo "✅ .env already exists"
fi
echo ""

# ------------------------------------------
# 4. Запускаем из директории проекта
# ------------------------------------------
echo "📦 Building and starting services..."
cd "$PROJECT_DIR"
$DOCKER_COMPOSE_CMD -f docker/docker-compose.yml up -d --build

echo ""
echo "⏳ Waiting for migrations..."
sleep 15

# Проверяем статус
$DOCKER_COMPOSE_CMD -f docker/docker-compose.yml ps

APP_PORT=$(grep APP_PORT "$ENV_FILE" | head -1 | cut -d= -f2)

echo ""
echo "✅ MirvMon is running!"
echo ""
echo "🌐 Web UI: http://localhost:${APP_PORT:-8080}"
echo "👤 Login: admin"
echo "🔑 Password: mirvmon2026"
echo ""
echo "📊 To check logs: $DOCKER_COMPOSE_CMD -f docker/docker-compose.yml logs -f app"
echo "🔧 To stop: $DOCKER_COMPOSE_CMD -f docker/docker-compose.yml down"
echo ""
