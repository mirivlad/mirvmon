#!/bin/bash
# deploy.sh — Быстрый разворот MirvMon на чистом сервере
# Использование: bash deploy.sh

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
# 2. Создаём .env если нет
# ------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

if [ ! -f "$SCRIPT_DIR/.env" ]; then
    echo "📝 Creating .env from template..."
    cp "$SCRIPT_DIR/.env.example" "$SCRIPT_DIR/.env"
    
    # Генерируем случайные пароли
    ROOT_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)
    DB_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)
    ADMIN_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)
    
    sed -i "s/DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=${ROOT_PASS}/" "$SCRIPT_DIR/.env"
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" "$SCRIPT_DIR/.env"
    sed -i "s/ADMIN_PASSWORD=.*/ADMIN_PASSWORD=${ADMIN_PASS}/" "$SCRIPT_DIR/.env"
    
    echo "🔐 Generated random passwords:"
    echo "   DB root: $ROOT_PASS"
    echo "   DB user: $DB_PASS"
    echo "   Admin web: $ADMIN_PASS"
    echo ""
    echo "⚠️  Save these! Change .env if you want custom passwords."
else
    echo "✅ .env already exists"
fi
echo ""

# ------------------------------------------
# 3. Запускаем
# ------------------------------------------
echo "📦 Building and starting services..."
$DOCKER_COMPOSE_CMD up -d --build

echo ""
echo "⏳ Waiting for migrations..."
sleep 10

# Проверяем статус
$DOCKER_COMPOSE_CMD ps

echo ""
echo "✅ MirvMon is running!"
echo ""
echo "🌐 Web UI: http://localhost:$(grep APP_PORT .env | cut -d= -f2)"
echo "👤 Login: admin"
echo "🔑 Password: $(grep ADMIN_PASSWORD .env | cut -d= -f2)"
echo ""
echo "📊 To check logs: $DOCKER_COMPOSE_CMD logs -f app"
echo "🔧 To stop: $DOCKER_COMPOSE_CMD down"
echo ""
