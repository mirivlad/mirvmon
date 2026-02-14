<?php
session_start();

// Проверяем существующую сессию
echo "Текущая сессия:\n";
echo "Session ID: " . session_id() . "\n";
echo "Session data: " . json_encode($_SESSION, JSON_PRETTY_PRINT) . "\n";

// Если сессия пуста, создаем пользователя admin
if (!isset($_SESSION["user_id"])) {
    $_SESSION["user_id"] = 1;
    $_SESSION["username"] = "admin";
    $_SESSION["role"] = "admin";
    $_SESSION["created_at"] = time();
    
    echo "\n✅ Сессия создана!\n";
} else {
    echo "\n✅ Сессия уже существует!\n";
    echo "Время создания: " . date("Y-m-d H:i:s", $_SESSION["created_at"]) . "\n";
}

// Информация о cookies
echo "\n🍪 Cookie информация:\n
