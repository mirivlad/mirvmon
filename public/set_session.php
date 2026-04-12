<?php
session_start();

require_once '/var/www/mon/vendor/autoload.php';

use Config\DatabaseConfig;
use App\Models\User;

$pdo = DatabaseConfig::getInstance();
$userModel = new User();

// Устанавливаем пароль admin в сессии напрямую
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin';

echo "✅ Сессия admin создана!\n";
echo "Session ID: " . session_id() . "\n";
echo "Session data: " . json_encode($_SESSION, JSON_PRETTY_PRINT) . "\n";
